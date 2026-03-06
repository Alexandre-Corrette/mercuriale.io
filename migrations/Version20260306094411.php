<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260306094411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contact_fournisseur table (MERC-93)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contact_fournisseur (id BINARY(16) NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) DEFAULT NULL, role VARCHAR(100) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, note LONGTEXT DEFAULT NULL, principal TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, fournisseur_id INT NOT NULL, INDEX idx_contact_fournisseur (fournisseur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE contact_fournisseur ADD CONSTRAINT FK_5832758C670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_fournisseur DROP FOREIGN KEY FK_5832758C670C757F');
        $this->addSql('DROP TABLE contact_fournisseur');
    }
}
