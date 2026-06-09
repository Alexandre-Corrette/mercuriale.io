<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le SIRET identifie de façon unique un établissement d'entreprise : il ne doit
 * jamais être dupliqué dans la table fournisseur. Cette migration normalise les
 * chaînes vides en NULL (sinon plusieurs '' violeraient l'unicité) puis pose un
 * index unique sur la colonne. Les valeurs NULL restent autorisées en plusieurs
 * exemplaires (fournisseurs sans SIRET renseigné).
 *
 * ⚠️ Pré-requis : la table ne doit plus contenir de doublons de SIRET non nuls.
 * La purge des doublons doit être faite AVANT d'appliquer cette migration.
 */
final class Version20260609120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique constraint on fournisseur.siret (normalize empty strings to NULL first)';
    }

    public function up(Schema $schema): void
    {
        // Normalise les chaînes vides en NULL pour éviter une collision d'unicité.
        $this->addSql("UPDATE fournisseur SET siret = NULL WHERE siret = ''");

        // Index unique idempotent : sûr que la preprod ait déjà reçu l'index manuellement ou non.
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_fournisseur_siret ON fournisseur (siret)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_fournisseur_siret');
    }
}
