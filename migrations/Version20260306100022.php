<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260306100022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create email_contact_fournisseur table (MERC-94)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_contact_fournisseur (id BINARY(16) NOT NULL, subject VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, sent_at DATETIME NOT NULL, status VARCHAR(255) NOT NULL, contact_id BINARY(16) NOT NULL, sent_by_id INT NOT NULL, INDEX idx_email_contact (contact_id), INDEX idx_email_sent_by (sent_by_id), INDEX idx_email_sent_at (sent_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE email_contact_fournisseur ADD CONSTRAINT FK_51816CFBE7A1254A FOREIGN KEY (contact_id) REFERENCES contact_fournisseur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_contact_fournisseur ADD CONSTRAINT FK_51816CFBA45BB98C FOREIGN KEY (sent_by_id) REFERENCES utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_contact_fournisseur DROP FOREIGN KEY FK_51816CFBE7A1254A');
        $this->addSql('ALTER TABLE email_contact_fournisseur DROP FOREIGN KEY FK_51816CFBA45BB98C');
        $this->addSql('DROP TABLE email_contact_fournisseur');
    }
}
