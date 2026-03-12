<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260312153008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MERC-116: Add unique constraint on bon_livraison (etablissement_id, fournisseur_id, numero_bl)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_bl_etablissement_fournisseur_numero ON bon_livraison (etablissement_id, fournisseur_id, numero_bl)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_bl_etablissement_fournisseur_numero ON bon_livraison');
    }
}
