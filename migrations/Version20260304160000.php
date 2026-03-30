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
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist(['facture_fournisseur'])) {
            $this->skipIf(true, 'Table facture_fournisseur does not exist yet');
            return;
        }
        $columns = array_map(fn ($c) => $c->getName(), $sm->listTableColumns('facture_fournisseur'));
        $this->skipIf(in_array('rapproche_le', $columns, true), 'Columns already exist');

        $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN rapproche_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN ecart_montant_ht NUMERIC(12, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE facture_fournisseur ADD COLUMN score_rapprochement INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS rapproche_le');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS ecart_montant_ht');
        $this->addSql('ALTER TABLE facture_fournisseur DROP COLUMN IF EXISTS score_rapprochement');
    }
}
