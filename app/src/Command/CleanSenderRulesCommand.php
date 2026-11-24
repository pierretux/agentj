<?php

namespace App\Command;

use App\Entity\SenderRule;
use App\Service\DnsService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Remove the sender rules that are useless:
 *
 * - user "block" rules identical to a "block" rule of the user's domain;
 * - rules of users that no longer exist;
 * - with --check-dns, "block" rules of sender domains that have been missing
 *   from the DNS for a while (spammers' domains expire quickly);
 * - rule addresses (mailaddr) that are no longer used by any rule.
 */
#[AsCommand(
    name: 'agentj:clean-sender-rules',
    description: 'Delete redundant and orphan sender rules, and optionally the bans of domains that no longer exist',
)]
class CleanSenderRulesCommand extends Command
{
    private const BLOCK_WB = ['B', 'N'];

    public function __construct(
        private Connection $connection,
        private DnsService $dnsService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Only display what would be deleted',
        );
        $this->addOption(
            'check-dns',
            null,
            InputOption::VALUE_NONE,
            'Also delete the bans of sender domains that no longer exist in the DNS',
        );
        $this->addOption(
            'dns-grace-days',
            null,
            InputOption::VALUE_REQUIRED,
            'Number of days a domain must have been missing from the DNS before its bans are deleted',
            '7',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $graceDays = (int) $input->getOption('dns-grace-days');
        if ($graceDays < 1) {
            $output->writeln('<error>--dns-grace-days must be at least 1.</error>');
            return Command::INVALID;
        }

        $prefix = $dryRun ? '[dry-run] ' : '';

        $count = $this->cleanRedundantUserRules($dryRun, $output);
        $output->writeln("{$prefix}{$count} user rule(s) redundant with a domain rule");

        $count = $this->cleanOrphanRules($dryRun);
        $output->writeln("{$prefix}{$count} rule(s) of deleted users");

        if ($input->getOption('check-dns')) {
            $this->cleanDeadDomainRules($dryRun, $graceDays, $output);
        }

        $count = $this->cleanUnusedRuleAddresses($dryRun);
        $output->writeln("{$prefix}{$count} unused rule address(es)");

        return Command::SUCCESS;
    }

    /**
     * Delete the "block" user rules for which the domain of the user already
     * has the same "block" rule. A rule is kept if a group of the user allows
     * the sender: the user rule is then the one that blocks it.
     */
    private function cleanRedundantUserRules(bool $dryRun, OutputInterface $output): int
    {
        $rules = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT w.rid, w.sid, w.priority, u.email AS user_email, m.email AS sender_email
            FROM wblist w
            INNER JOIN users u ON u.id = w.rid
            INNER JOIN domain d ON d.id = u.domain_id
            INNER JOIN users du ON du.email = CONCAT('@', d.domain)
            INNER JOIN wblist dw ON dw.rid = du.id AND dw.sid = w.sid
                AND dw.priority = :domainPriority AND dw.wb IN (:blockWb)
            INNER JOIN mailaddr m ON m.id = w.sid
            LEFT JOIN wblist g ON g.rid = w.rid AND g.sid = w.sid
                AND g.priority > :domainPriority AND g.priority < :userPriority
                AND g.wb NOT IN (:blockWb)
            WHERE w.priority = :userPriority
            AND w.group_id IS NULL
            AND w.wb IN (:blockWb)
            AND w.rid <> du.id
            AND g.rid IS NULL
        SQL, [
            'domainPriority' => SenderRule::PRIORITY_DOMAIN,
            'userPriority' => SenderRule::PRIORITY_USER,
            'blockWb' => self::BLOCK_WB,
        ], [
            'blockWb' => ArrayParameterType::STRING,
        ]);

        foreach ($rules as $rule) {
            if ($output->isVerbose()) {
                $output->writeln("  {$rule['user_email']}: {$rule['sender_email']}");
            }
        }

        if (!$dryRun) {
            $this->connection->transactional(function (Connection $connection) use ($rules): void {
                foreach ($rules as $rule) {
                    $connection->executeStatement(
                        'DELETE FROM wblist WHERE rid = :rid AND sid = :sid AND priority = :priority',
                        [
                            'rid' => $rule['rid'],
                            'sid' => $rule['sid'],
                            'priority' => $rule['priority'],
                        ],
                    );
                }
            });
        }

        return count($rules);
    }

    /**
     * Delete the rules whose user no longer exists (old databases may lack
     * the foreign key that cascades the deletion).
     */
    private function cleanOrphanRules(bool $dryRun): int
    {
        $from = <<<'SQL'
            FROM wblist w
            LEFT JOIN users u ON u.id = w.rid
            WHERE u.id IS NULL
        SQL;

        if ($dryRun) {
            return (int) $this->connection->fetchOne("SELECT COUNT(*) {$from}");
        }

        return (int) $this->connection->executeStatement("DELETE w {$from}");
    }

    /**
     * Delete the "block" rules (user, group and domain ones) of the sender
     * domains (`@domain.tld` and `@.domain.tld`) that are missing from the DNS
     * since at least $graceDays days, i.e. on every run during this period:
     * the date of the first failure is stored and reset when the domain
     * resolves again, so a temporary DNS failure doesn't delete anything.
     */
    private function cleanDeadDomainRules(bool $dryRun, int $graceDays, OutputInterface $output): void
    {
        $prefix = $dryRun ? '[dry-run] ' : '';

        $ruleAddresses = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT m.id, m.email, m.dns_failed_at
            FROM mailaddr m
            WHERE m.email LIKE '@%'
            AND (
                EXISTS (SELECT 1 FROM wblist w WHERE w.sid = m.id AND w.wb IN (:blockWb))
                OR EXISTS (SELECT 1 FROM groups_wblist g WHERE g.sid = m.id AND g.wb IN (:blockWb))
            )
        SQL, [
            'blockWb' => self::BLOCK_WB,
        ], [
            'blockWb' => ArrayParameterType::STRING,
        ]);

        $managedDomains = array_map(
            fn (mixed $domain): string => mb_strtolower((string) $domain),
            $this->connection->fetchFirstColumn('SELECT domain FROM domain'),
        );

        $existing = [];
        $missing = [];
        foreach ($ruleAddresses as $ruleAddress) {
            $domain = mb_strtolower(ltrim(substr((string) $ruleAddress['email'], 1), '.'));
            if ($domain === '' || in_array($domain, $managedDomains, true)) {
                continue;
            }

            if ($this->dnsService->domainExists($domain)) {
                $existing[] = $ruleAddress;
            } else {
                $missing[] = $ruleAddress;
            }
        }

        // If the resolver is down (or most of the lookups fail), don't take
        // any decision.
        $checkedCount = count($existing) + count($missing);
        if ($checkedCount > 0 && count($missing) * 2 > $checkedCount) {
            $output->writeln(
                "<comment>{$prefix}DNS check skipped: " . count($missing) . " of {$checkedCount} "
                . 'sender domains are missing from the DNS, the resolver is probably failing.</comment>'
            );
            return;
        }

        $now = new \DateTimeImmutable();
        $limit = $now->modify("-{$graceDays} days");

        $toReset = [];
        $toMark = [];
        $toDelete = [];
        foreach ($existing as $ruleAddress) {
            if ($ruleAddress['dns_failed_at'] !== null) {
                $toReset[] = $ruleAddress['id'];
            }
        }
        foreach ($missing as $ruleAddress) {
            if ($ruleAddress['dns_failed_at'] === null) {
                $toMark[] = $ruleAddress['id'];
            } elseif (new \DateTimeImmutable($ruleAddress['dns_failed_at']) <= $limit) {
                $toDelete[] = $ruleAddress;
            }
        }

        foreach ($toDelete as $ruleAddress) {
            if ($output->isVerbose()) {
                $output->writeln("  {$ruleAddress['email']} (missing since {$ruleAddress['dns_failed_at']})");
            }
        }

        if (!$dryRun) {
            $this->connection->transactional(function (Connection $connection) use (
                $toReset,
                $toMark,
                $toDelete,
                $now,
            ): void {
                if ($toReset) {
                    $connection->executeStatement(
                        'UPDATE mailaddr SET dns_failed_at = NULL WHERE id IN (:ids)',
                        ['ids' => $toReset],
                        ['ids' => ArrayParameterType::INTEGER],
                    );
                }

                if ($toMark) {
                    $connection->executeStatement(
                        'UPDATE mailaddr SET dns_failed_at = :now WHERE id IN (:ids)',
                        ['now' => $now->format('Y-m-d H:i:s'), 'ids' => $toMark],
                        ['ids' => ArrayParameterType::INTEGER],
                    );
                }

                $ids = array_column($toDelete, 'id');
                if ($ids) {
                    foreach (['wblist', 'groups_wblist'] as $table) {
                        $connection->executeStatement(
                            "DELETE FROM {$table} WHERE sid IN (:ids) AND wb IN (:blockWb)",
                            ['ids' => $ids, 'blockWb' => self::BLOCK_WB],
                            ['ids' => ArrayParameterType::INTEGER, 'blockWb' => ArrayParameterType::STRING],
                        );
                    }
                }
            });
        }

        $output->writeln("{$prefix}" . count($toDelete) . ' domain(s) missing from the DNS, bans deleted');
        $output->writeln("{$prefix}" . count($toMark) . ' domain(s) newly missing from the DNS, will be deleted '
            . "if still missing in {$graceDays} days");
    }

    /**
     * Delete the rule addresses that are no longer used by any rule.
     */
    private function cleanUnusedRuleAddresses(bool $dryRun): int
    {
        $from = <<<'SQL'
            FROM mailaddr m
            LEFT JOIN wblist w ON w.sid = m.id
            LEFT JOIN groups_wblist g ON g.sid = m.id
        SQL;
        $where = 'WHERE w.sid IS NULL AND g.sid IS NULL';

        if ($this->connection->createSchemaManager()->tablesExist(['out_wblist'])) {
            $from .= ' LEFT JOIN out_wblist o ON o.sid = m.id';
            $where .= ' AND o.sid IS NULL';
        }

        if ($dryRun) {
            return (int) $this->connection->fetchOne("SELECT COUNT(DISTINCT m.id) {$from} {$where}");
        }

        return (int) $this->connection->executeStatement("DELETE m {$from} {$where}");
    }
}
