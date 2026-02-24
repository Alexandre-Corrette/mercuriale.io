<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260219100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MERC-51: Create login_log table for authentication audit trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE login_log (
            id INT AUTO_INCREMENT NOT NULL,
            utilisateur_id INT DEFAULT NULL,
            email VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_login_log_date (created_at),
            INDEX idx_login_log_user (utilisateur_id),
            INDEX idx_login_log_status (status),
            PRIMARY KEY(id),
            CONSTRAINT FK_login_log_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE login_log');
    }
}
