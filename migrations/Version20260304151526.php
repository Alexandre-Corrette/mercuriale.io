<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304151526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pdp_account_id, e_invoicing_enabled, e_invoicing_enabled_at to etablissement';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $columns = array_map(fn ($c) => $c->getName(), $sm->listTableColumns('etablissement'));

        if (in_array('pdp_account_id', $columns, true)) {
            $this->skipIf(true, 'Columns already exist');
        }

        $this->addSql('ALTER TABLE etablissement ADD COLUMN pdp_account_id VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE etablissement ADD COLUMN e_invoicing_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE etablissement ADD COLUMN e_invoicing_enabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE etablissement DROP COLUMN IF EXISTS pdp_account_id');
        $this->addSql('ALTER TABLE etablissement DROP COLUMN IF EXISTS e_invoicing_enabled');
        $this->addSql('ALTER TABLE etablissement DROP COLUMN IF EXISTS e_invoicing_enabled_at');
    }
}
