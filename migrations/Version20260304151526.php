<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add e-invoicing fields to etablissement for B2Brouter PDP integration.
 */
final class Version20260304151526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pdp_account_id, e_invoicing_enabled, e_invoicing_enabled_at to etablissement';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->hasTable('etablissement') || $schema->getTable('etablissement')->hasColumn('pdp_account_id'),
            'Table does not exist yet or columns already exist (migrated from MySQL to PostgreSQL)'
        );

        $this->addSql('ALTER TABLE etablissement ADD pdp_account_id VARCHAR(100) DEFAULT NULL, ADD e_invoicing_enabled TINYINT DEFAULT 0 NOT NULL, ADD e_invoicing_enabled_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE etablissement DROP pdp_account_id, DROP e_invoicing_enabled, DROP e_invoicing_enabled_at');
    }
}
