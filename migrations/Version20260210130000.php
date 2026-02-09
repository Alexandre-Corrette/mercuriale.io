<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MERC-32: Convert mercuriale_import.etablissement ManyToOne to ManyToMany join table';
    }

    public function up(Schema $schema): void
    {
        // Create the join table
        $this->addSql('CREATE TABLE mercuriale_import_etablissement (
            mercuriale_import_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            etablissement_id INT NOT NULL,
            INDEX IDX_MI_ETAB_IMPORT (mercuriale_import_id),
            INDEX IDX_MI_ETAB_ETABLISSEMENT (etablissement_id),
            CONSTRAINT FK_MI_ETAB_IMPORT FOREIGN KEY (mercuriale_import_id) REFERENCES mercuriale_import (id) ON DELETE CASCADE,
            CONSTRAINT FK_MI_ETAB_ETABLISSEMENT FOREIGN KEY (etablissement_id) REFERENCES etablissement (id) ON DELETE CASCADE,
            PRIMARY KEY(mercuriale_import_id, etablissement_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Migrate existing data
        $this->addSql('INSERT INTO mercuriale_import_etablissement (mercuriale_import_id, etablissement_id)
            SELECT id, etablissement_id FROM mercuriale_import WHERE etablissement_id IS NOT NULL');

        // Drop the old FK, index, and column
        $this->addSql('ALTER TABLE mercuriale_import DROP FOREIGN KEY FK_mercuriale_import_etablissement');
        $this->addSql('DROP INDEX idx_mercuriale_import_etablissement ON mercuriale_import');
        $this->addSql('ALTER TABLE mercuriale_import DROP COLUMN etablissement_id');
    }

    public function down(Schema $schema): void
    {
        // Re-add the column
        $this->addSql('ALTER TABLE mercuriale_import ADD etablissement_id INT DEFAULT NULL');

        // Migrate data back (take the first etablissement if multiple)
        $this->addSql('UPDATE mercuriale_import mi
            SET mi.etablissement_id = (
                SELECT mie.etablissement_id FROM mercuriale_import_etablissement mie
                WHERE mie.mercuriale_import_id = mi.id LIMIT 1
            )');

        // Re-add FK and index
        $this->addSql('ALTER TABLE mercuriale_import ADD CONSTRAINT FK_MERCURIALE_IMPORT_ETABLISSEMENT
            FOREIGN KEY (etablissement_id) REFERENCES etablissement (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_mercuriale_import_etablissement ON mercuriale_import (etablissement_id)');

        // Drop the join table
        $this->addSql('DROP TABLE mercuriale_import_etablissement');
    }
}
