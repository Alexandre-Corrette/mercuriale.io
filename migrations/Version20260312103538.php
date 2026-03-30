<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260312103538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MERC-112: Abonnement entity, UtilisateurOrganisation junction, siren on Organisation, isPrimary + siret on Etablissement, data backfill';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->hasTable('abonnement'),
            'Tables already exist (migrated from MySQL to PostgreSQL)'
        );

        // Create abonnement table
        $this->addSql('CREATE TABLE abonnement (id INT AUTO_INCREMENT NOT NULL, plan VARCHAR(20) DEFAULT \'trial\' NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME DEFAULT NULL, stripe_subscription_id VARCHAR(255) DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, organisation_id INT NOT NULL, UNIQUE INDEX UNIQ_351268BB9E6B1585 (organisation_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE abonnement ADD CONSTRAINT FK_351268BB9E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');

        // Create utilisateur_organisation junction table
        $this->addSql('CREATE TABLE utilisateur_organisation (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) DEFAULT \'owner\' NOT NULL, created_at DATETIME NOT NULL, utilisateur_id INT NOT NULL, organisation_id INT NOT NULL, INDEX IDX_8F131647FB88E14F (utilisateur_id), INDEX IDX_8F1316479E6B1585 (organisation_id), UNIQUE INDEX unique_utilisateur_organisation (utilisateur_id, organisation_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE utilisateur_organisation ADD CONSTRAINT FK_8F131647FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE utilisateur_organisation ADD CONSTRAINT FK_8F1316479E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');

        // Add siren to organisation
        $this->addSql('ALTER TABLE organisation ADD siren VARCHAR(9) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E6E132B4DB8BBA08 ON organisation (siren)');

        // Add siret and isPrimary to etablissement
        $this->addSql('ALTER TABLE etablissement ADD siret VARCHAR(14) DEFAULT NULL, ADD is_primary TINYINT DEFAULT 0 NOT NULL');

        // Data migration: create Abonnement(plan=single, active=true) for each existing Organisation
        $this->addSql('INSERT INTO abonnement (organisation_id, plan, starts_at, active, created_at, updated_at) SELECT id, \'single\', NOW(), 1, NOW(), NOW() FROM organisation');

        // Data migration: backfill utilisateur_organisation from existing utilisateur.organisation_id
        $this->addSql('INSERT INTO utilisateur_organisation (utilisateur_id, organisation_id, role, created_at) SELECT id, organisation_id, \'owner\', NOW() FROM utilisateur WHERE organisation_id IS NOT NULL');

        // Data migration: extract SIREN from SIRET (first 9 chars)
        $this->addSql('UPDATE organisation SET siren = LEFT(siret, 9) WHERE siret IS NOT NULL AND LENGTH(siret) = 14');

        // Data migration: mark first etablissement per organisation as isPrimary
        $this->addSql('UPDATE etablissement e INNER JOIN (SELECT MIN(id) AS min_id, organisation_id FROM etablissement GROUP BY organisation_id) sub ON e.id = sub.min_id SET e.is_primary = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE abonnement DROP FOREIGN KEY FK_351268BB9E6B1585');
        $this->addSql('ALTER TABLE utilisateur_organisation DROP FOREIGN KEY FK_8F131647FB88E14F');
        $this->addSql('ALTER TABLE utilisateur_organisation DROP FOREIGN KEY FK_8F1316479E6B1585');
        $this->addSql('DROP TABLE abonnement');
        $this->addSql('DROP TABLE utilisateur_organisation');
        $this->addSql('ALTER TABLE etablissement DROP siret, DROP is_primary');
        $this->addSql('DROP INDEX UNIQ_E6E132B4DB8BBA08 ON organisation');
        $this->addSql('ALTER TABLE organisation DROP siren');
    }
}
