<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330071900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow null unite_base_id on produit for import tolerance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit ALTER COLUMN unite_base_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit ALTER COLUMN unite_base_id SET NOT NULL');
    }
}
