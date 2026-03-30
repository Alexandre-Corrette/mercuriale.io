<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330072000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow null unite_achat_id on produit_fournisseur for import tolerance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit_fournisseur ALTER COLUMN unite_achat_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit_fournisseur ALTER COLUMN unite_achat_id SET NOT NULL');
    }
}
