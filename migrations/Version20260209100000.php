<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260209100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create refresh_tokens table for JWT refresh token management';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE refresh_tokens (
            id INT AUTO_INCREMENT NOT NULL,
            refresh_token VARCHAR(128) NOT NULL,
            username VARCHAR(255) NOT NULL,
            valid DATETIME NOT NULL,
            revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_9BACE7E1C74F2195 (refresh_token),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE refresh_tokens');
    }
}
