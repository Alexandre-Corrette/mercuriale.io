<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260219110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MERC-52: Create audit_log table for entity change tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (
            id INT AUTO_INCREMENT NOT NULL,
            utilisateur_id INT DEFAULT NULL,
            action VARCHAR(10) NOT NULL,
            entity_type VARCHAR(100) NOT NULL,
            entity_id INT NOT NULL,
            entity_label VARCHAR(255) DEFAULT NULL,
            changes JSON DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_audit_log_date (created_at),
            INDEX idx_audit_log_entity (entity_type, entity_id),
            INDEX idx_audit_log_user (utilisateur_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_audit_log_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_log');
    }
}
