<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260306102008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add websiteUrl to fournisseur (MERC-95)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$schema->hasTable('fournisseur') || $schema->getTable('fournisseur')->hasColumn('website_url'),
            'Table does not exist yet or column already exists (migrated from MySQL to PostgreSQL)'
        );

        $this->addSql('ALTER TABLE fournisseur ADD website_url VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fournisseur DROP website_url');
    }
}
