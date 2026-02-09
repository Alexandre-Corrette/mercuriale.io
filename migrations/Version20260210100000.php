<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create push_subscriptions table for Web Push notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE push_subscriptions (
            id INT AUTO_INCREMENT NOT NULL,
            utilisateur_id INT NOT NULL,
            endpoint VARCHAR(500) NOT NULL,
            p256dh_key VARCHAR(255) NOT NULL,
            auth_token VARCHAR(255) NOT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_PUSH_ENDPOINT (endpoint),
            INDEX IDX_PUSH_UTILISATEUR (utilisateur_id),
            CONSTRAINT FK_PUSH_UTILISATEUR FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE push_subscriptions');
    }
}
