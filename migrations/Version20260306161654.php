<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260306161654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add verified_at, trial_ends_at, stripe fields to organisation';
    }

    public function up(Schema $schema): void
    {
        $columns = array_map(fn ($c) => $c->getName(), $this->connection->createSchemaManager()->listTableColumns('organisation'));
        $this->skipIf(in_array('verified_at', $columns, true), 'Columns already exist');

        $this->addSql('ALTER TABLE organisation ADD COLUMN verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE organisation ADD COLUMN trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE organisation ADD COLUMN stripe_account_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE organisation ADD COLUMN stripe_verification_session_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organisation DROP COLUMN IF EXISTS verified_at');
        $this->addSql('ALTER TABLE organisation DROP COLUMN IF EXISTS trial_ends_at');
        $this->addSql('ALTER TABLE organisation DROP COLUMN IF EXISTS stripe_account_id');
        $this->addSql('ALTER TABLE organisation DROP COLUMN IF EXISTS stripe_verification_session_id');
    }
}
