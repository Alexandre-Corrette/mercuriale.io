<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260306102008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add websiteUrl to fournisseur (MERC-95)';
    }

    public function up(Schema $schema): void
    {
        $columns = array_map(fn ($c) => $c->getName(), $this->connection->createSchemaManager()->listTableColumns('fournisseur'));
        $this->skipIf(in_array('website_url', $columns, true), 'Column already exists');

        $this->addSql('ALTER TABLE fournisseur ADD COLUMN website_url VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fournisseur DROP COLUMN IF EXISTS website_url');
    }
}
