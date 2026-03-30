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
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist(['facture_fournisseur'])) {
            $this->skipIf(true, 'Table facture_fournisseur does not exist yet');
            return;
        }
        $columns = array_map(fn ($c) => $c->getName(), $sm->listTableColumns('facture_fournisseur'));
        $this->skipIf(in_array('source', $columns, true), 'Columns already exist');

        // New columns for OCR channel
        $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT \'B2BROUTER\'');
        $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN ocr_raw_data JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN ocr_processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN fichier_original_nom VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN created_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD CONSTRAINT FK_facture_created_by FOREIGN KEY (created_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_facture_created_by ON facture_fournisseur (created_by_id)');

        // Make fields nullable for OCR uploads
        $this->addSql('ALTER TABLE facture_fournisseur ALTER COLUMN external_id DROP NOT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ALTER COLUMN numero_facture DROP NOT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ALTER COLUMN date_emission DROP NOT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ALTER COLUMN montant_ht DROP NOT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ALTER COLUMN montant_ttc DROP NOT NULL');

        // Index on source for filtering
        $this->addSql('CREATE INDEX idx_facture_source ON facture_fournisseur (source)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_facture_source');
        $this->addSql('DROP INDEX IF EXISTS idx_facture_created_by');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS source');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS ocr_raw_data');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS ocr_processed_at');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS fichier_original_nom');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS created_by_id');
    }
}
