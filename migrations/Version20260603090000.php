<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image_hash column on bon_livraison (SHA-256 for duplicate detection)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison ADD image_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison DROP image_hash');
    }
}
