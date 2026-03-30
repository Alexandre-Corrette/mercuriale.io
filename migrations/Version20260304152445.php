<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create facture_fournisseur and ligne_facture_fournisseur tables for B2Brouter e-invoicing.
 */
final class Version20260304152445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create facture_fournisseur and ligne_facture_fournisseur tables';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->hasTable('facture_fournisseur'),
            'Tables already exist (migrated from MySQL to PostgreSQL)'
        );

        $this->addSql('CREATE TABLE facture_fournisseur (id BINARY(16) NOT NULL, external_id VARCHAR(100) NOT NULL, numero_facture VARCHAR(100) NOT NULL, date_emission DATE NOT NULL, fournisseur_nom VARCHAR(255) DEFAULT NULL, fournisseur_tva VARCHAR(30) DEFAULT NULL, fournisseur_siren VARCHAR(9) DEFAULT NULL, acheteur_nom VARCHAR(255) DEFAULT NULL, acheteur_tva VARCHAR(30) DEFAULT NULL, statut VARCHAR(20) DEFAULT \'RECUE\' NOT NULL, montant_ht NUMERIC(12, 2) NOT NULL, montant_tva NUMERIC(12, 2) DEFAULT NULL, montant_ttc NUMERIC(12, 2) NOT NULL, devise VARCHAR(3) DEFAULT \'EUR\' NOT NULL, commentaire LONGTEXT DEFAULT NULL, motif_refus LONGTEXT DEFAULT NULL, document_original_path VARCHAR(500) DEFAULT NULL, acceptee_le DATETIME DEFAULT NULL, payee_le DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, fournisseur_id INT DEFAULT NULL, etablissement_id INT NOT NULL, bon_livraison_id INT DEFAULT NULL, validated_by_id INT DEFAULT NULL, INDEX idx_facture_fournisseur (fournisseur_id), INDEX idx_facture_etablissement (etablissement_id), INDEX idx_facture_statut (statut), INDEX IDX_311911C4D8D16068 (bon_livraison_id), INDEX IDX_311911C4C69DE5E5 (validated_by_id), UNIQUE INDEX uniq_facture_external_id (external_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE ligne_facture_fournisseur (id INT AUTO_INCREMENT NOT NULL, external_id VARCHAR(100) DEFAULT NULL, code_article VARCHAR(50) DEFAULT NULL, designation VARCHAR(255) NOT NULL, quantite NUMERIC(10, 3) NOT NULL, prix_unitaire NUMERIC(10, 4) NOT NULL, montant_ligne NUMERIC(12, 2) NOT NULL, taux_tva NUMERIC(5, 2) DEFAULT NULL, unite VARCHAR(20) DEFAULT NULL, facture_id BINARY(16) NOT NULL, produit_id INT DEFAULT NULL, INDEX idx_ligne_facture (facture_id), INDEX IDX_EA175387F347EFB (produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE facture_fournisseur ADD CONSTRAINT FK_311911C4670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE facture_fournisseur ADD CONSTRAINT FK_311911C4FF631228 FOREIGN KEY (etablissement_id) REFERENCES etablissement (id)');
        $this->addSql('ALTER TABLE facture_fournisseur ADD CONSTRAINT FK_311911C4D8D16068 FOREIGN KEY (bon_livraison_id) REFERENCES bon_livraison (id)');
        $this->addSql('ALTER TABLE facture_fournisseur ADD CONSTRAINT FK_311911C4C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ligne_facture_fournisseur ADD CONSTRAINT FK_EA1753877F2DEE08 FOREIGN KEY (facture_id) REFERENCES facture_fournisseur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_facture_fournisseur ADD CONSTRAINT FK_EA175387F347EFB FOREIGN KEY (produit_id) REFERENCES produit_fournisseur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facture_fournisseur DROP FOREIGN KEY FK_311911C4670C757F');
        $this->addSql('ALTER TABLE facture_fournisseur DROP FOREIGN KEY FK_311911C4FF631228');
        $this->addSql('ALTER TABLE facture_fournisseur DROP FOREIGN KEY FK_311911C4D8D16068');
        $this->addSql('ALTER TABLE facture_fournisseur DROP FOREIGN KEY FK_311911C4C69DE5E5');
        $this->addSql('ALTER TABLE ligne_facture_fournisseur DROP FOREIGN KEY FK_EA1753877F2DEE08');
        $this->addSql('ALTER TABLE ligne_facture_fournisseur DROP FOREIGN KEY FK_EA175387F347EFB');
        $this->addSql('DROP TABLE facture_fournisseur');
        $this->addSql('DROP TABLE ligne_facture_fournisseur');
    }
}
