<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rapprochement fields to facture_fournisseur (MERC-12)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facture_fournisseur ADD rapproche_le DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD ecart_montant_ht NUMERIC(12, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD score_rapprochement INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facture_fournisseur DROP rapproche_le');
        $this->addSql('ALTER TABLE facture_fournisseur DROP ecart_montant_ht');
        $this->addSql('ALTER TABLE facture_fournisseur DROP score_rapprochement');
    }
}
