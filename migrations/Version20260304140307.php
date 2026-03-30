<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304140307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MERC-69: Create avoir_fournisseur and ligne_avoir tables';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->hasTable('avoir_fournisseur'),
            'Tables already exist (migrated from MySQL to PostgreSQL)'
        );

        $this->addSql('CREATE TABLE avoir_fournisseur (id BINARY(16) NOT NULL, reference VARCHAR(50) DEFAULT NULL, statut VARCHAR(20) DEFAULT \'DEMANDE\' NOT NULL, motif VARCHAR(30) NOT NULL, montant_ht NUMERIC(12, 2) DEFAULT NULL, montant_tva NUMERIC(12, 2) DEFAULT NULL, montant_ttc NUMERIC(12, 2) DEFAULT NULL, commentaire LONGTEXT DEFAULT NULL, demande_le DATETIME NOT NULL, recu_le DATETIME DEFAULT NULL, impute_le DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, fournisseur_id INT NOT NULL, etablissement_id INT NOT NULL, bon_livraison_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, validated_by_id INT DEFAULT NULL, INDEX idx_avoir_fournisseur (fournisseur_id), INDEX idx_avoir_etablissement (etablissement_id), INDEX idx_avoir_bon_livraison (bon_livraison_id), INDEX idx_avoir_statut (statut), INDEX IDX_4C383676B03A8386 (created_by_id), INDEX IDX_4C383676C69DE5E5 (validated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE ligne_avoir (id INT AUTO_INCREMENT NOT NULL, designation VARCHAR(255) NOT NULL, quantite NUMERIC(10, 3) NOT NULL, prix_unitaire NUMERIC(10, 4) NOT NULL, montant_ligne NUMERIC(12, 2) NOT NULL, avoir_id BINARY(16) NOT NULL, produit_id INT DEFAULT NULL, INDEX idx_ligne_avoir (avoir_id), INDEX IDX_6637F073F347EFB (produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE avoir_fournisseur ADD CONSTRAINT FK_4C383676670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE avoir_fournisseur ADD CONSTRAINT FK_4C383676FF631228 FOREIGN KEY (etablissement_id) REFERENCES etablissement (id)');
        $this->addSql('ALTER TABLE avoir_fournisseur ADD CONSTRAINT FK_4C383676D8D16068 FOREIGN KEY (bon_livraison_id) REFERENCES bon_livraison (id)');
        $this->addSql('ALTER TABLE avoir_fournisseur ADD CONSTRAINT FK_4C383676B03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE avoir_fournisseur ADD CONSTRAINT FK_4C383676C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ligne_avoir ADD CONSTRAINT FK_6637F073C36D46DB FOREIGN KEY (avoir_id) REFERENCES avoir_fournisseur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_avoir ADD CONSTRAINT FK_6637F073F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE avoir_fournisseur DROP FOREIGN KEY FK_4C383676670C757F');
        $this->addSql('ALTER TABLE avoir_fournisseur DROP FOREIGN KEY FK_4C383676FF631228');
        $this->addSql('ALTER TABLE avoir_fournisseur DROP FOREIGN KEY FK_4C383676D8D16068');
        $this->addSql('ALTER TABLE avoir_fournisseur DROP FOREIGN KEY FK_4C383676B03A8386');
        $this->addSql('ALTER TABLE avoir_fournisseur DROP FOREIGN KEY FK_4C383676C69DE5E5');
        $this->addSql('ALTER TABLE ligne_avoir DROP FOREIGN KEY FK_6637F073C36D46DB');
        $this->addSql('ALTER TABLE ligne_avoir DROP FOREIGN KEY FK_6637F073F347EFB');
        $this->addSql('DROP TABLE ligne_avoir');
        $this->addSql('DROP TABLE avoir_fournisseur');
    }
}
