<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mailaddr.dns_failed_at to clean the rules of the domains that no longer exist.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailaddr ADD dns_failed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailaddr DROP dns_failed_at');
    }
}
