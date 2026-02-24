<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260219160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MERC-55: Add new OCR extraction fields to ligne_bon_livraison';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_bon_livraison
            ADD unite_livraison VARCHAR(20) DEFAULT NULL,
            ADD quantite_facturee NUMERIC(12, 4) DEFAULT NULL,
            ADD unite_facturation VARCHAR(20) DEFAULT NULL,
            ADD majoration_decote NUMERIC(10, 4) DEFAULT NULL,
            ADD code_tva VARCHAR(10) DEFAULT NULL,
            ADD origine VARCHAR(10) DEFAULT NULL,
            ADD numero_ligne_bl INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_bon_livraison
            DROP COLUMN unite_livraison,
            DROP COLUMN quantite_facturee,
            DROP COLUMN unite_facturation,
            DROP COLUMN majoration_decote,
            DROP COLUMN code_tva,
            DROP COLUMN origine,
            DROP COLUMN numero_ligne_bl');
    }
}