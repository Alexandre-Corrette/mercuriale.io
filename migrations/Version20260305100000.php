<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add source/OCR fields to facture_fournisseur, make externalId/amounts nullable (MERC-78)';
    }

    public function up(Schema $schema): void
    {
        // New columns for OCR channel
        $this->addSql('ALTER TABLE facture_fournisseur ADD source VARCHAR(20) NOT NULL DEFAULT \'B2BROUTER\'');
        $this->addSql('ALTER TABLE facture_fournisseur ADD ocr_raw_data JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD ocr_processed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD fichier_original_nom VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD created_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD CONSTRAINT FK_facture_created_by FOREIGN KEY (created_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_facture_created_by ON facture_fournisseur (created_by_id)');

        // Make fields nullable for OCR uploads (data filled after OCR processing)
        $this->addSql('ALTER TABLE facture_fournisseur MODIFY external_id VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur MODIFY numero_facture VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur MODIFY date_emission DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur MODIFY montant_ht NUMERIC(12, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur MODIFY montant_ttc NUMERIC(12, 2) DEFAULT NULL');

        // Index on source for filtering
        $this->addSql('CREATE INDEX idx_facture_source ON facture_fournisseur (source)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_facture_source ON facture_fournisseur');

        $this->addSql('ALTER TABLE facture_fournisseur DROP source');
        $this->addSql('ALTER TABLE facture_fournisseur DROP ocr_raw_data');
        $this->addSql('ALTER TABLE facture_fournisseur DROP ocr_processed_at');
        $this->addSql('ALTER TABLE facture_fournisseur DROP fichier_original_nom');
        $this->addSql('ALTER TABLE facture_fournisseur DROP FOREIGN KEY FK_facture_created_by');
        $this->addSql('DROP INDEX idx_facture_created_by ON facture_fournisseur');
        $this->addSql('ALTER TABLE facture_fournisseur DROP created_by_id');

        $this->addSql('ALTER TABLE facture_fournisseur MODIFY external_id VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur MODIFY numero_facture VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur MODIFY date_emission DATE NOT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur MODIFY montant_ht NUMERIC(12, 2) NOT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur MODIFY montant_ttc NUMERIC(12, 2) NOT NULL');
    }
}
