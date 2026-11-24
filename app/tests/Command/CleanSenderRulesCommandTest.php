<?php

namespace App\Tests\Command;

use App\Entity\Domain;
use App\Entity\RuleAddress;
use App\Entity\SenderRule;
use App\Entity\User;
use App\Service\DnsService;
use App\Tests\Factory\DomainFactory;
use App\Tests\Factory\RuleAddressFactory;
use App\Tests\Factory\SenderRuleFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CleanSenderRulesCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    /** @var string[] */
    private array $missingDomains = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $test = $this;
        self::getContainer()->set(DnsService::class, new class ($test) extends DnsService {
            public function __construct(private CleanSenderRulesCommandTest $test)
            {
            }

            public function domainExists(string $domain): bool
            {
                return !in_array($domain, $this->test->getMissingDomains(), true);
            }
        });
    }

    /**
     * @return string[]
     */
    public function getMissingDomains(): array
    {
        return $this->missingDomains;
    }

    public function testItDeletesUserBansRedundantWithADomainBan(): void
    {
        $domain = DomainFactory::createOne();
        $user = UserFactory::new()->user($domain)->create();
        $sender = RuleAddressFactory::createOne(['email' => 'spammer@spam.test']);
        $this->createRule($this->getDomainUser($domain), $sender, 'block', SenderRule::PRIORITY_DOMAIN);
        $this->createRule($user, $sender, 'block', SenderRule::PRIORITY_USER);

        $tester = $this->execute(['--dry-run' => true]);

        self::assertStringContainsString('[dry-run] 1 user rule(s) redundant', $tester->getDisplay());
        self::assertTrue($this->ruleExists($user, $sender, SenderRule::PRIORITY_USER));

        $tester = $this->execute([]);

        self::assertStringContainsString('1 user rule(s) redundant', $tester->getDisplay());
        self::assertFalse($this->ruleExists($user, $sender, SenderRule::PRIORITY_USER));
        self::assertTrue($this->ruleExists($this->getDomainUser($domain), $sender, SenderRule::PRIORITY_DOMAIN));
    }

    public function testItKeepsUserBansThatAreNotRedundant(): void
    {
        $domain = DomainFactory::createOne();
        $otherDomain = DomainFactory::createOne();
        $user = UserFactory::new()->user($domain)->create();
        $userInGroup = UserFactory::new()->user($domain)->create();
        $allowedByDomain = RuleAddressFactory::createOne(['email' => 'allowed@sender.test']);
        $bannedElsewhere = RuleAddressFactory::createOne(['email' => 'other@sender.test']);
        $allowedByGroup = RuleAddressFactory::createOne(['email' => 'group@sender.test']);
        $domainUser = $this->getDomainUser($domain);
        // The domain allows the sender: the user ban is the one that blocks it.
        $this->createRule($domainUser, $allowedByDomain, 'allow', SenderRule::PRIORITY_DOMAIN);
        $this->createRule($user, $allowedByDomain, 'block', SenderRule::PRIORITY_USER);
        // Another domain bans the sender, not the user's one.
        $this->createRule($this->getDomainUser($otherDomain), $bannedElsewhere, 'block', SenderRule::PRIORITY_DOMAIN);
        $this->createRule($user, $bannedElsewhere, 'block', SenderRule::PRIORITY_USER);
        // A group of the user allows the sender, over the domain ban.
        $this->createRule($domainUser, $allowedByGroup, 'block', SenderRule::PRIORITY_DOMAIN);
        $this->createRule($userInGroup, $allowedByGroup, 'allow', SenderRule::PRIORITY_GROUP);
        $this->createRule($userInGroup, $allowedByGroup, 'block', SenderRule::PRIORITY_USER);

        $tester = $this->execute([]);

        self::assertStringContainsString('0 user rule(s) redundant', $tester->getDisplay());
        self::assertTrue($this->ruleExists($user, $allowedByDomain, SenderRule::PRIORITY_USER));
        self::assertTrue($this->ruleExists($user, $bannedElsewhere, SenderRule::PRIORITY_USER));
        self::assertTrue($this->ruleExists($userInGroup, $allowedByGroup, SenderRule::PRIORITY_USER));
    }

    public function testItDeletesUnusedRuleAddresses(): void
    {
        $domain = DomainFactory::createOne();
        $used = RuleAddressFactory::createOne(['email' => 'used@sender.test']);
        $unused = RuleAddressFactory::createOne(['email' => 'unused@sender.test']);
        $this->createRule($this->getDomainUser($domain), $used, 'block', SenderRule::PRIORITY_DOMAIN);

        $tester = $this->execute(['--dry-run' => true]);

        self::assertStringContainsString('[dry-run] 1 unused rule address(es)', $tester->getDisplay());
        self::assertTrue($this->ruleAddressExists($unused));

        $tester = $this->execute([]);

        self::assertStringContainsString('1 unused rule address(es)', $tester->getDisplay());
        self::assertFalse($this->ruleAddressExists($unused));
        self::assertTrue($this->ruleAddressExists($used));
        // The catch-all address used by the default domain rules.
        self::assertNotNull(RuleAddressFactory::repository()->findOneBy(['email' => '@.']));
    }

    public function testItDeletesBansOfDomainsMissingFromTheDnsAfterTheGracePeriod(): void
    {
        $domain = DomainFactory::createOne();
        $domainUser = $this->getDomainUser($domain);
        $user = UserFactory::new()->user($domain)->create();
        $dead = RuleAddressFactory::createOne(['email' => '@dead.test']);
        $deadSubdomains = RuleAddressFactory::createOne(['email' => '@.dead-too.test']);
        $deadAllowed = RuleAddressFactory::createOne(['email' => '@dead-allowed.test']);
        $this->createRule($domainUser, $dead, 'block', SenderRule::PRIORITY_DOMAIN);
        $this->createRule($user, $deadSubdomains, 'block', SenderRule::PRIORITY_USER);
        $this->createRule($user, $deadAllowed, 'allow', SenderRule::PRIORITY_USER);
        foreach (['@alive1.test', '@alive2.test', '@alive3.test', '@alive4.test'] as $email) {
            $this->createRule($domainUser, RuleAddressFactory::createOne(['email' => $email]), 'block', 0);
        }
        $this->missingDomains = ['dead.test', 'dead-too.test', 'dead-allowed.test'];

        // First run: the domains are only marked as missing.
        $tester = $this->execute(['--check-dns' => true]);

        self::assertStringContainsString('0 domain(s) missing from the DNS, bans deleted', $tester->getDisplay());
        self::assertStringContainsString('2 domain(s) newly missing', $tester->getDisplay());
        self::assertTrue($this->ruleExists($domainUser, $dead, SenderRule::PRIORITY_DOMAIN));
        self::assertNotNull($this->getDnsFailedAt($dead));
        // Allow rules are not concerned.
        self::assertNull($this->getDnsFailedAt($deadAllowed));

        // Still missing after the grace period: the bans are deleted.
        $this->getConnection()->executeStatement(
            'UPDATE mailaddr SET dns_failed_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE dns_failed_at IS NOT NULL'
        );
        $tester = $this->execute(['--check-dns' => true]);

        self::assertStringContainsString('2 domain(s) missing from the DNS, bans deleted', $tester->getDisplay());
        self::assertFalse($this->ruleExists($domainUser, $dead, SenderRule::PRIORITY_DOMAIN));
        self::assertFalse($this->ruleExists($user, $deadSubdomains, SenderRule::PRIORITY_USER));
        self::assertTrue($this->ruleExists($user, $deadAllowed, SenderRule::PRIORITY_USER));
        self::assertFalse($this->ruleAddressExists($dead));
    }

    public function testItResetsTheDnsFailureWhenTheDomainResolvesAgain(): void
    {
        $domain = DomainFactory::createOne();
        $domainUser = $this->getDomainUser($domain);
        $flaky = RuleAddressFactory::createOne(['email' => '@flaky.test']);
        $this->createRule($domainUser, $flaky, 'block', SenderRule::PRIORITY_DOMAIN);
        foreach (['@alive1.test', '@alive2.test'] as $email) {
            $this->createRule($domainUser, RuleAddressFactory::createOne(['email' => $email]), 'block', 0);
        }
        $this->missingDomains = ['flaky.test'];
        $this->execute(['--check-dns' => true]);
        self::assertNotNull($this->getDnsFailedAt($flaky));

        $this->missingDomains = [];
        $this->getConnection()->executeStatement(
            'UPDATE mailaddr SET dns_failed_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE dns_failed_at IS NOT NULL'
        );
        $this->execute(['--check-dns' => true]);

        self::assertNull($this->getDnsFailedAt($flaky));
        self::assertTrue($this->ruleExists($domainUser, $flaky, SenderRule::PRIORITY_DOMAIN));
    }

    public function testItSkipsTheDnsCheckWhenMostLookupsFail(): void
    {
        $domain = DomainFactory::createOne();
        $domainUser = $this->getDomainUser($domain);
        $sender1 = RuleAddressFactory::createOne(['email' => '@one.test']);
        $sender2 = RuleAddressFactory::createOne(['email' => '@two.test']);
        $this->createRule($domainUser, $sender1, 'block', SenderRule::PRIORITY_DOMAIN);
        $this->createRule($domainUser, $sender2, 'block', SenderRule::PRIORITY_DOMAIN);
        $this->missingDomains = ['one.test', 'two.test'];

        $tester = $this->execute(['--check-dns' => true]);

        self::assertStringContainsString('DNS check skipped', $tester->getDisplay());
        self::assertNull($this->getDnsFailedAt($sender1));
        self::assertNull($this->getDnsFailedAt($sender2));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function execute(array $options): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('agentj:clean-sender-rules'));
        $exitCode = $tester->execute($options);
        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        return $tester;
    }

    /**
     * @param 'allow'|'block' $wb
     */
    private function createRule(User $user, RuleAddress $ruleAddress, string $wb, int $priority): void
    {
        SenderRuleFactory::createOne([
            'user' => $user,
            'senderRuleAddress' => $ruleAddress,
            'wb' => $wb,
            'priority' => $priority,
        ]);
    }

    private function getDomainUser(Domain $domain): User
    {
        $domainUser = UserFactory::repository()->findOneBy(['email' => '@' . $domain->getDomain()]);
        self::assertInstanceOf(User::class, $domainUser);

        return $domainUser;
    }

    private function ruleExists(User $user, RuleAddress $ruleAddress, int $priority): bool
    {
        return (bool) $this->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM wblist WHERE rid = :rid AND sid = :sid AND priority = :priority',
            ['rid' => $user->getId(), 'sid' => $ruleAddress->getId(), 'priority' => $priority],
        );
    }

    private function ruleAddressExists(RuleAddress $ruleAddress): bool
    {
        return (bool) $this->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM mailaddr WHERE id = :id',
            ['id' => $ruleAddress->getId()],
        );
    }

    private function getDnsFailedAt(RuleAddress $ruleAddress): mixed
    {
        return $this->getConnection()->fetchOne(
            'SELECT dns_failed_at FROM mailaddr WHERE id = :id',
            ['id' => $ruleAddress->getId()],
        );
    }

    private function getConnection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
