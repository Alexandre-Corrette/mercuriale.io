<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260306094411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contact_fournisseur table (MERC-93)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $this->connection->createSchemaManager()->tablesExist(['contact_fournisseur']),
            'Table contact_fournisseur already exists'
        );

        $this->addSql('CREATE TABLE contact_fournisseur (id UUID NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) DEFAULT NULL, role VARCHAR(100) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, note TEXT DEFAULT NULL, principal BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, fournisseur_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_contact_fournisseur ON contact_fournisseur (fournisseur_id)');
        $this->addSql('ALTER TABLE contact_fournisseur ADD CONSTRAINT FK_5832758C670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS contact_fournisseur');
    }
}
