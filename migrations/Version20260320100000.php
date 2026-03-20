<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add EN_COURS_OCR and ECHEC_OCR values to StatutBonLivraison enum (VARCHAR column, no DDL needed)';
    }

    public function up(Schema $schema): void
    {
        // StatutBonLivraison is stored as VARCHAR(20) with Doctrine enumType mapping.
        // New enum cases EN_COURS_OCR and ECHEC_OCR are handled at application level.
        // No DDL change required — this migration serves as documentation.
        $this->addSql('SELECT 1');
    }

    public function down(Schema $schema): void
    {
        // Rolling back enum values would require updating all rows using the new statuses
        // back to BROUILLON before removing the application-level enum cases.
        // This cannot be done safely in a generic migration.
        throw new \LogicException(
            'Cannot rollback StatutBonLivraison enum values. '
            . 'Manually update rows with EN_COURS_OCR or ECHEC_OCR to BROUILLON before removing the enum cases.'
        );
    }
}
