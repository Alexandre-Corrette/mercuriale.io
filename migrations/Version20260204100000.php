<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the mercuriale_import table for storing temporary import data.
 */
final class Version20260204100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mercuriale_import table for CSV/XLSX import workflow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mercuriale_import (
            id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            fournisseur_id INT NOT NULL,
            etablissement_id INT DEFAULT NULL,
            created_by_id INT NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            parsed_data JSON NOT NULL,
            column_mapping JSON DEFAULT NULL,
            preview_result JSON DEFAULT NULL,
            import_result JSON DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            total_rows INT NOT NULL,
            detected_headers JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id),
            INDEX idx_mercuriale_import_fournisseur (fournisseur_id),
            INDEX idx_mercuriale_import_etablissement (etablissement_id),
            INDEX idx_mercuriale_import_status (status),
            INDEX idx_mercuriale_import_expires (expires_at),
            CONSTRAINT FK_mercuriale_import_fournisseur FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id),
            CONSTRAINT FK_mercuriale_import_etablissement FOREIGN KEY (etablissement_id) REFERENCES etablissement (id),
            CONSTRAINT FK_mercuriale_import_created_by FOREIGN KEY (created_by_id) REFERENCES utilisateur (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mercuriale_import');
    }
}
