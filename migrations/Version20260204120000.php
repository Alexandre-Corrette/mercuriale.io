<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refactors the Fournisseur model to be shared between organisations.
 * Creates organisation_fournisseur junction table and migrates existing data.
 */
final class Version20260204120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refactor Fournisseur model: create organisation_fournisseur junction table and migrate data';
    }

    public function up(Schema $schema): void
    {
        // 1. Create the new junction table
        $this->addSql('CREATE TABLE organisation_fournisseur (
            id INT AUTO_INCREMENT NOT NULL,
            organisation_id INT NOT NULL,
            fournisseur_id INT NOT NULL,
            code_client VARCHAR(50) DEFAULT NULL,
            contact_commercial VARCHAR(255) DEFAULT NULL,
            email_commande VARCHAR(255) DEFAULT NULL,
            actif TINYINT(1) DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_org_fournisseur_organisation (organisation_id),
            INDEX IDX_org_fournisseur_fournisseur (fournisseur_id),
            UNIQUE INDEX unique_org_fournisseur (organisation_id, fournisseur_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // 2. Add foreign keys
        $this->addSql('ALTER TABLE organisation_fournisseur ADD CONSTRAINT FK_org_fournisseur_organisation FOREIGN KEY (organisation_id) REFERENCES organisation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE organisation_fournisseur ADD CONSTRAINT FK_org_fournisseur_fournisseur FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id) ON DELETE CASCADE');

        // 3. Migrate existing data from fournisseur to organisation_fournisseur
        $this->addSql('INSERT INTO organisation_fournisseur (organisation_id, fournisseur_id, actif, created_at, updated_at)
            SELECT organisation_id, id, actif, created_at, updated_at
            FROM fournisseur
            WHERE organisation_id IS NOT NULL');

        // 4. Drop the foreign key and index from fournisseur table
        $this->addSql('ALTER TABLE fournisseur DROP FOREIGN KEY FK_369ECA329E6B1585');
        $this->addSql('DROP INDEX idx_fournisseur_organisation ON fournisseur');

        // 5. Remove the organisation_id column from fournisseur
        $this->addSql('ALTER TABLE fournisseur DROP COLUMN organisation_id');
    }

    public function down(Schema $schema): void
    {
        // 1. Add back organisation_id column to fournisseur
        $this->addSql('ALTER TABLE fournisseur ADD organisation_id INT DEFAULT NULL');

        // 2. Restore data from organisation_fournisseur to fournisseur
        // Note: This will only restore one organisation per fournisseur (the first one found)
        $this->addSql('UPDATE fournisseur f
            INNER JOIN (
                SELECT fournisseur_id, MIN(organisation_id) as organisation_id
                FROM organisation_fournisseur
                GROUP BY fournisseur_id
            ) of ON f.id = of.fournisseur_id
            SET f.organisation_id = of.organisation_id');

        // 3. Add back the foreign key and index
        $this->addSql('CREATE INDEX idx_fournisseur_organisation ON fournisseur (organisation_id)');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA329E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');

        // 4. Make organisation_id NOT NULL again
        $this->addSql('ALTER TABLE fournisseur MODIFY organisation_id INT NOT NULL');

        // 5. Drop the junction table
        $this->addSql('DROP TABLE organisation_fournisseur');
    }
}
