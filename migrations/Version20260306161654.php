<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260306161654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organisation ADD verified_at DATETIME DEFAULT NULL, ADD trial_ends_at DATETIME DEFAULT NULL, ADD stripe_account_id VARCHAR(255) DEFAULT NULL, ADD stripe_verification_session_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organisation DROP verified_at, DROP trial_ends_at, DROP stripe_account_id, DROP stripe_verification_session_id');
    }
}
