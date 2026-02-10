<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create fournisseur_etablissement join table for direct ManyToMany relationship';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fournisseur_etablissement (
            fournisseur_id INT NOT NULL,
            etablissement_id INT NOT NULL,
            INDEX IDX_FOURN_ETAB_FOURNISSEUR (fournisseur_id),
            INDEX IDX_FOURN_ETAB_ETABLISSEMENT (etablissement_id),
            CONSTRAINT FK_FOURN_ETAB_FOURNISSEUR FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id) ON DELETE CASCADE,
            CONSTRAINT FK_FOURN_ETAB_ETABLISSEMENT FOREIGN KEY (etablissement_id) REFERENCES etablissement (id) ON DELETE CASCADE,
            PRIMARY KEY(fournisseur_id, etablissement_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fournisseur_etablissement');
    }
}
