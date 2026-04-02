<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MERC-143: Create signalement_produit and photo_signalement tables';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $this->connection->createSchemaManager()->tablesExist(['signalement_produit']),
            'Table signalement_produit already exists'
        );

        $this->addSql('CREATE TABLE signalement_produit (id UUID NOT NULL, reference VARCHAR(20) DEFAULT NULL, statut VARCHAR(20) DEFAULT \'SIGNALE\' NOT NULL, motif VARCHAR(30) NOT NULL, quantite_concernee NUMERIC(10, 3) NOT NULL, commentaire TEXT DEFAULT NULL, reclame_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, resolu_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ligne_bon_livraison_id INT NOT NULL, etablissement_id INT NOT NULL, avoir_id UUID DEFAULT NULL, created_by_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D70DA4D3AEA34913 ON signalement_produit (reference)');
        $this->addSql('CREATE INDEX IDX_D70DA4D35C64C0D7 ON signalement_produit (ligne_bon_livraison_id)');
        $this->addSql('CREATE INDEX IDX_D70DA4D3C36D46DB ON signalement_produit (avoir_id)');
        $this->addSql('CREATE INDEX IDX_D70DA4D3B03A8386 ON signalement_produit (created_by_id)');
        $this->addSql('CREATE INDEX idx_signalement_etablissement ON signalement_produit (etablissement_id)');
        $this->addSql('CREATE INDEX idx_signalement_statut ON signalement_produit (statut)');
        $this->addSql('CREATE INDEX idx_signalement_motif ON signalement_produit (motif)');

        $this->addSql('CREATE TABLE photo_signalement (id UUID NOT NULL, filename VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, file_size INT NOT NULL, taken_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, signalement_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FA22BDC865C5E57E ON photo_signalement (signalement_id)');

        $this->addSql('ALTER TABLE signalement_produit ADD CONSTRAINT FK_D70DA4D35C64C0D7 FOREIGN KEY (ligne_bon_livraison_id) REFERENCES ligne_bon_livraison (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE signalement_produit ADD CONSTRAINT FK_D70DA4D3FF631228 FOREIGN KEY (etablissement_id) REFERENCES etablissement (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE signalement_produit ADD CONSTRAINT FK_D70DA4D3C36D46DB FOREIGN KEY (avoir_id) REFERENCES avoir_fournisseur (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE signalement_produit ADD CONSTRAINT FK_D70DA4D3B03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE photo_signalement ADD CONSTRAINT FK_FA22BDC865C5E57E FOREIGN KEY (signalement_id) REFERENCES signalement_produit (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS photo_signalement');
        $this->addSql('DROP TABLE IF EXISTS signalement_produit');
    }
}
