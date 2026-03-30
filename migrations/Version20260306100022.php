<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260306100022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create email_contact_fournisseur table (MERC-94)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $this->connection->createSchemaManager()->tablesExist(['email_contact_fournisseur']),
            'Table email_contact_fournisseur already exists'
        );

        $this->addSql('CREATE TABLE email_contact_fournisseur (id UUID NOT NULL, subject VARCHAR(255) NOT NULL, body TEXT NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(255) NOT NULL, contact_id UUID NOT NULL, sent_by_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_email_contact ON email_contact_fournisseur (contact_id)');
        $this->addSql('CREATE INDEX idx_email_sent_by ON email_contact_fournisseur (sent_by_id)');
        $this->addSql('CREATE INDEX idx_email_sent_at ON email_contact_fournisseur (sent_at)');
        $this->addSql('ALTER TABLE email_contact_fournisseur ADD CONSTRAINT FK_51816CFBE7A1254A FOREIGN KEY (contact_id) REFERENCES contact_fournisseur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_contact_fournisseur ADD CONSTRAINT FK_51816CFBA45BB98C FOREIGN KEY (sent_by_id) REFERENCES utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS email_contact_fournisseur');
    }
}
