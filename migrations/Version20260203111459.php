<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260203111459 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alerte_controle (id INT AUTO_INCREMENT NOT NULL, type_alerte VARCHAR(30) NOT NULL, message VARCHAR(500) NOT NULL, valeur_attendue NUMERIC(12, 4) DEFAULT NULL, valeur_recue NUMERIC(12, 4) DEFAULT NULL, ecart_pct NUMERIC(8, 2) DEFAULT NULL, statut VARCHAR(20) DEFAULT \'NOUVELLE\' NOT NULL, commentaire LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, traitee_at DATETIME DEFAULT NULL, ligne_bl_id INT NOT NULL, traitee_par_id INT DEFAULT NULL, INDEX IDX_EE56732B3338F50D (traitee_par_id), INDEX idx_alerte_ligne (ligne_bl_id), INDEX idx_alerte_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE bon_livraison (id INT AUTO_INCREMENT NOT NULL, numero_bl VARCHAR(100) DEFAULT NULL, numero_commande VARCHAR(100) DEFAULT NULL, date_livraison DATE NOT NULL, statut VARCHAR(20) DEFAULT \'BROUILLON\' NOT NULL, image_path VARCHAR(500) DEFAULT NULL, donnees_brutes JSON DEFAULT NULL, total_ht NUMERIC(12, 2) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, validated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, etablissement_id INT NOT NULL, fournisseur_id INT NOT NULL, created_by_id INT DEFAULT NULL, validated_by_id INT DEFAULT NULL, INDEX IDX_31A531A4B03A8386 (created_by_id), INDEX IDX_31A531A4C69DE5E5 (validated_by_id), INDEX idx_bl_etablissement (etablissement_id), INDEX idx_bl_fournisseur (fournisseur_id), INDEX idx_bl_date (date_livraison), INDEX idx_bl_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE categorie_produit (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, code VARCHAR(50) NOT NULL, ordre INT DEFAULT 0 NOT NULL, parent_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_7626428577153098 (code), INDEX IDX_76264285727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE conversion_unite (id INT AUTO_INCREMENT NOT NULL, facteur NUMERIC(15, 6) NOT NULL, unite_source_id INT NOT NULL, unite_cible_id INT NOT NULL, INDEX IDX_B80E7D6811CD3824 (unite_source_id), INDEX IDX_B80E7D68E60BC51A (unite_cible_id), UNIQUE INDEX unique_conversion (unite_source_id, unite_cible_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE etablissement (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, adresse VARCHAR(255) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, organisation_id INT NOT NULL, INDEX idx_etablissement_organisation (organisation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE fournisseur (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, code VARCHAR(50) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, siret VARCHAR(14) DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, organisation_id INT NOT NULL, INDEX idx_fournisseur_organisation (organisation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE ligne_bon_livraison (id INT AUTO_INCREMENT NOT NULL, code_produit_bl VARCHAR(50) DEFAULT NULL, designation_bl VARCHAR(255) NOT NULL, quantite_commandee NUMERIC(10, 3) DEFAULT NULL, quantite_livree NUMERIC(10, 3) NOT NULL, prix_unitaire NUMERIC(10, 4) NOT NULL, total_ligne NUMERIC(12, 4) NOT NULL, statut_controle VARCHAR(20) DEFAULT \'NON_CONTROLE\' NOT NULL, valide TINYINT DEFAULT 0 NOT NULL, ordre INT DEFAULT 0 NOT NULL, bon_livraison_id INT NOT NULL, produit_fournisseur_id INT DEFAULT NULL, unite_id INT NOT NULL, INDEX IDX_3AC630C5EC4A74AB (unite_id), INDEX idx_ligne_bl (bon_livraison_id), INDEX idx_ligne_produit_fournisseur (produit_fournisseur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE mercuriale (id INT AUTO_INCREMENT NOT NULL, prix_negocie NUMERIC(10, 4) NOT NULL, date_debut DATE NOT NULL, date_fin DATE DEFAULT NULL, seuil_alerte_pct NUMERIC(5, 2) DEFAULT \'5.00\' NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, produit_fournisseur_id INT NOT NULL, etablissement_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_A61A64F7B03A8386 (created_by_id), INDEX idx_mercuriale_produit_fournisseur (produit_fournisseur_id), INDEX idx_mercuriale_etablissement (etablissement_id), INDEX idx_mercuriale_dates (date_debut, date_fin), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE organisation (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, siret VARCHAR(14) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE produit (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, code_interne VARCHAR(50) DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, categorie_id INT DEFAULT NULL, unite_base_id INT NOT NULL, INDEX IDX_29A5EC27D30E9EE2 (unite_base_id), INDEX idx_produit_categorie (categorie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE produit_fournisseur (id INT AUTO_INCREMENT NOT NULL, code_fournisseur VARCHAR(50) NOT NULL, designation_fournisseur VARCHAR(255) NOT NULL, conditionnement NUMERIC(10, 3) DEFAULT \'1.000\' NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, produit_id INT DEFAULT NULL, fournisseur_id INT NOT NULL, unite_achat_id INT NOT NULL, INDEX IDX_48868EB6B1F04A04 (unite_achat_id), INDEX idx_produit_fournisseur_fournisseur (fournisseur_id), INDEX idx_produit_fournisseur_produit (produit_id), UNIQUE INDEX unique_fournisseur_code (fournisseur_id, code_fournisseur), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE unite (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, code VARCHAR(10) NOT NULL, type VARCHAR(20) NOT NULL, ordre INT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_1D64C11877153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE utilisateur_etablissement (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(50) DEFAULT \'ROLE_VIEWER\' NOT NULL, created_at DATETIME NOT NULL, utilisateur_id INT NOT NULL, etablissement_id INT NOT NULL, INDEX IDX_42008AEFB88E14F (utilisateur_id), INDEX IDX_42008AEFF631228 (etablissement_id), UNIQUE INDEX unique_utilisateur_etablissement (utilisateur_id, etablissement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE alerte_controle ADD CONSTRAINT FK_EE56732BCE8C6D99 FOREIGN KEY (ligne_bl_id) REFERENCES ligne_bon_livraison (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alerte_controle ADD CONSTRAINT FK_EE56732B3338F50D FOREIGN KEY (traitee_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bon_livraison ADD CONSTRAINT FK_31A531A4FF631228 FOREIGN KEY (etablissement_id) REFERENCES etablissement (id)');
        $this->addSql('ALTER TABLE bon_livraison ADD CONSTRAINT FK_31A531A4670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE bon_livraison ADD CONSTRAINT FK_31A531A4B03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bon_livraison ADD CONSTRAINT FK_31A531A4C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE categorie_produit ADD CONSTRAINT FK_76264285727ACA70 FOREIGN KEY (parent_id) REFERENCES categorie_produit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversion_unite ADD CONSTRAINT FK_B80E7D6811CD3824 FOREIGN KEY (unite_source_id) REFERENCES unite (id)');
        $this->addSql('ALTER TABLE conversion_unite ADD CONSTRAINT FK_B80E7D68E60BC51A FOREIGN KEY (unite_cible_id) REFERENCES unite (id)');
        $this->addSql('ALTER TABLE etablissement ADD CONSTRAINT FK_20FD592C9E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA329E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
        $this->addSql('ALTER TABLE ligne_bon_livraison ADD CONSTRAINT FK_3AC630C5D8D16068 FOREIGN KEY (bon_livraison_id) REFERENCES bon_livraison (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_bon_livraison ADD CONSTRAINT FK_3AC630C5649D40A FOREIGN KEY (produit_fournisseur_id) REFERENCES produit_fournisseur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ligne_bon_livraison ADD CONSTRAINT FK_3AC630C5EC4A74AB FOREIGN KEY (unite_id) REFERENCES unite (id)');
        $this->addSql('ALTER TABLE mercuriale ADD CONSTRAINT FK_A61A64F7649D40A FOREIGN KEY (produit_fournisseur_id) REFERENCES produit_fournisseur (id)');
        $this->addSql('ALTER TABLE mercuriale ADD CONSTRAINT FK_A61A64F7FF631228 FOREIGN KEY (etablissement_id) REFERENCES etablissement (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mercuriale ADD CONSTRAINT FK_A61A64F7B03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC27BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie_produit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC27D30E9EE2 FOREIGN KEY (unite_base_id) REFERENCES unite (id)');
        $this->addSql('ALTER TABLE produit_fournisseur ADD CONSTRAINT FK_48868EB6F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit_fournisseur ADD CONSTRAINT FK_48868EB6670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE produit_fournisseur ADD CONSTRAINT FK_48868EB6B1F04A04 FOREIGN KEY (unite_achat_id) REFERENCES unite (id)');
        $this->addSql('ALTER TABLE utilisateur_etablissement ADD CONSTRAINT FK_42008AEFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE utilisateur_etablissement ADD CONSTRAINT FK_42008AEFF631228 FOREIGN KEY (etablissement_id) REFERENCES etablissement (id)');
        $this->addSql('ALTER TABLE utilisateur ADD updated_at DATETIME NOT NULL, ADD organisation_id INT NOT NULL, DROP last_login_at, CHANGE actif actif TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE utilisateur ADD CONSTRAINT FK_1D1C63B39E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
        $this->addSql('CREATE INDEX idx_utilisateur_organisation ON utilisateur (organisation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alerte_controle DROP FOREIGN KEY FK_EE56732BCE8C6D99');
        $this->addSql('ALTER TABLE alerte_controle DROP FOREIGN KEY FK_EE56732B3338F50D');
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A4FF631228');
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A4670C757F');
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A4B03A8386');
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A4C69DE5E5');
        $this->addSql('ALTER TABLE categorie_produit DROP FOREIGN KEY FK_76264285727ACA70');
        $this->addSql('ALTER TABLE conversion_unite DROP FOREIGN KEY FK_B80E7D6811CD3824');
        $this->addSql('ALTER TABLE conversion_unite DROP FOREIGN KEY FK_B80E7D68E60BC51A');
        $this->addSql('ALTER TABLE etablissement DROP FOREIGN KEY FK_20FD592C9E6B1585');
        $this->addSql('ALTER TABLE fournisseur DROP FOREIGN KEY FK_369ECA329E6B1585');
        $this->addSql('ALTER TABLE ligne_bon_livraison DROP FOREIGN KEY FK_3AC630C5D8D16068');
        $this->addSql('ALTER TABLE ligne_bon_livraison DROP FOREIGN KEY FK_3AC630C5649D40A');
        $this->addSql('ALTER TABLE ligne_bon_livraison DROP FOREIGN KEY FK_3AC630C5EC4A74AB');
        $this->addSql('ALTER TABLE mercuriale DROP FOREIGN KEY FK_A61A64F7649D40A');
        $this->addSql('ALTER TABLE mercuriale DROP FOREIGN KEY FK_A61A64F7FF631228');
        $this->addSql('ALTER TABLE mercuriale DROP FOREIGN KEY FK_A61A64F7B03A8386');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC27BCF5E72D');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC27D30E9EE2');
        $this->addSql('ALTER TABLE produit_fournisseur DROP FOREIGN KEY FK_48868EB6F347EFB');
        $this->addSql('ALTER TABLE produit_fournisseur DROP FOREIGN KEY FK_48868EB6670C757F');
        $this->addSql('ALTER TABLE produit_fournisseur DROP FOREIGN KEY FK_48868EB6B1F04A04');
        $this->addSql('ALTER TABLE utilisateur_etablissement DROP FOREIGN KEY FK_42008AEFB88E14F');
        $this->addSql('ALTER TABLE utilisateur_etablissement DROP FOREIGN KEY FK_42008AEFF631228');
        $this->addSql('DROP TABLE alerte_controle');
        $this->addSql('DROP TABLE bon_livraison');
        $this->addSql('DROP TABLE categorie_produit');
        $this->addSql('DROP TABLE conversion_unite');
        $this->addSql('DROP TABLE etablissement');
        $this->addSql('DROP TABLE fournisseur');
        $this->addSql('DROP TABLE ligne_bon_livraison');
        $this->addSql('DROP TABLE mercuriale');
        $this->addSql('DROP TABLE organisation');
        $this->addSql('DROP TABLE produit');
        $this->addSql('DROP TABLE produit_fournisseur');
        $this->addSql('DROP TABLE unite');
        $this->addSql('DROP TABLE utilisateur_etablissement');
        $this->addSql('ALTER TABLE utilisateur DROP FOREIGN KEY FK_1D1C63B39E6B1585');
        $this->addSql('DROP INDEX idx_utilisateur_organisation ON utilisateur');
        $this->addSql('ALTER TABLE utilisateur ADD last_login_at DATETIME DEFAULT NULL, DROP updated_at, DROP organisation_id, CHANGE actif actif TINYINT NOT NULL');
    }
}
