<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CONTESTEE status fields, create transition_facture and invoice_sequence tables';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        // Add columns to facture_fournisseur if table exists and columns don't
        if ($sm->tablesExist(['facture_fournisseur'])) {
            $columns = array_map(fn ($c) => $c->getName(), $sm->listTableColumns('facture_fournisseur'));
            if (!in_array('contestee_le', $columns, true)) {
                $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN contestee_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
                $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN date_echeance DATE DEFAULT NULL');
                $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN reference_paiement VARCHAR(50) DEFAULT NULL');
                $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN last_reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
                $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN internal_reference VARCHAR(30) DEFAULT NULL');
                $this->addSql('CREATE UNIQUE INDEX uniq_facture_internal_ref ON facture_fournisseur (internal_reference)');
                $this->addSql('CREATE INDEX idx_facture_date_echeance ON facture_fournisseur (date_echeance)');
            }
        }

        // Create transition_facture
        if (!$sm->tablesExist(['transition_facture'])) {
            $this->addSql('CREATE TABLE transition_facture (id UUID NOT NULL, facture_id UUID NOT NULL, from_statut VARCHAR(20) NOT NULL, to_statut VARCHAR(20) NOT NULL, user_id INT NOT NULL, motif TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
            $this->addSql('CREATE INDEX idx_transition_facture ON transition_facture (facture_id)');
            $this->addSql('CREATE INDEX idx_transition_created_at ON transition_facture (created_at)');
            $this->addSql('ALTER TABLE transition_facture ADD CONSTRAINT FK_transition_facture FOREIGN KEY (facture_id) REFERENCES facture_fournisseur (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE transition_facture ADD CONSTRAINT FK_transition_user FOREIGN KEY (user_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        }

        // Create invoice_sequence
        if (!$sm->tablesExist(['invoice_sequence'])) {
            $this->addSql('CREATE TABLE invoice_sequence (id UUID NOT NULL, organisation_id INT NOT NULL, year INT NOT NULL, last_number INT NOT NULL DEFAULT 0, prefix VARCHAR(20) NOT NULL DEFAULT \'FAC\', suffix VARCHAR(10) DEFAULT NULL, padding_length INT NOT NULL DEFAULT 5, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
            $this->addSql('CREATE UNIQUE INDEX uniq_sequence_org_year ON invoice_sequence (organisation_id, year)');
            $this->addSql('ALTER TABLE invoice_sequence ADD CONSTRAINT FK_sequence_organisation FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS invoice_sequence');
        $this->addSql('DROP TABLE IF EXISTS transition_facture');
        $this->addSql('DROP INDEX IF EXISTS uniq_facture_internal_ref');
        $this->addSql('DROP INDEX IF EXISTS idx_facture_date_echeance');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS contestee_le');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS date_echeance');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS reference_paiement');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS last_reminder_sent_at');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS internal_reference');
    }
}
