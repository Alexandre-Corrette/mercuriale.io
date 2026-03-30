<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CONTESTEE status, dateEcheance, referencePaiement, lastReminderSentAt, internalReference fields on facture_fournisseur. Create transition_facture audit trail and invoice_sequence tables.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->hasTable('transition_facture'),
            'Tables and columns already exist (migrated from MySQL to PostgreSQL)'
        );

        // New columns on facture_fournisseur
        $this->addSql('ALTER TABLE facture_fournisseur ADD contestee_le DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD date_echeance DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD reference_paiement VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD last_reminder_sent_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD internal_reference VARCHAR(30) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_facture_internal_ref ON facture_fournisseur (internal_reference)');
        $this->addSql('CREATE INDEX idx_facture_date_echeance ON facture_fournisseur (date_echeance)');

        // Transition facture (audit trail)
        $this->addSql('CREATE TABLE transition_facture (
            id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            facture_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            from_statut VARCHAR(20) NOT NULL,
            to_statut VARCHAR(20) NOT NULL,
            user_id INT NOT NULL,
            motif LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_transition_facture (facture_id),
            INDEX idx_transition_created_at (created_at),
            CONSTRAINT FK_transition_facture FOREIGN KEY (facture_id) REFERENCES facture_fournisseur (id) ON DELETE CASCADE,
            CONSTRAINT FK_transition_user FOREIGN KEY (user_id) REFERENCES utilisateur (id) ON DELETE CASCADE,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Invoice sequence
        $this->addSql('CREATE TABLE invoice_sequence (
            id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            organisation_id INT NOT NULL,
            year INT NOT NULL,
            last_number INT NOT NULL DEFAULT 0,
            prefix VARCHAR(20) NOT NULL DEFAULT \'FAC\',
            suffix VARCHAR(10) DEFAULT NULL,
            padding_length INT NOT NULL DEFAULT 5,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_sequence_org_year (organisation_id, year),
            CONSTRAINT FK_sequence_organisation FOREIGN KEY (organisation_id) REFERENCES organisation (id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE invoice_sequence');
        $this->addSql('DROP TABLE transition_facture');

        $this->addSql('DROP INDEX uniq_facture_internal_ref ON facture_fournisseur');
        $this->addSql('DROP INDEX idx_facture_date_echeance ON facture_fournisseur');
        $this->addSql('ALTER TABLE facture_fournisseur DROP contestee_le');
        $this->addSql('ALTER TABLE facture_fournisseur DROP date_echeance');
        $this->addSql('ALTER TABLE facture_fournisseur DROP reference_paiement');
        $this->addSql('ALTER TABLE facture_fournisseur DROP last_reminder_sent_at');
        $this->addSql('ALTER TABLE facture_fournisseur DROP internal_reference');
    }
}
