<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed de la taxonomie produit (premier jet, 10 familles grossières).
 * Les catégories sont globales (pas de scope organisation). Idempotent via
 * ON CONFLICT (code) — le code est unique.
 */
final class Version20260610120000 extends AbstractMigration
{
    private const CATEGORIES = [
        ['VIANDES', 'Viandes & Charcuterie', 1],
        ['MAREE', 'Marée & Poissons', 2],
        ['FRUITS_LEGUMES', 'Fruits & Légumes', 3],
        ['CREMERIE', 'Crémerie, Œufs & Fromages', 4],
        ['EPICERIE_SALEE', 'Épicerie salée', 5],
        ['EPICERIE_SUCREE', 'Épicerie sucrée & Pâtisserie', 6],
        ['BOISSONS_SA', 'Boissons sans alcool', 7],
        ['ALCOOLS', 'Alcools & Spiritueux', 8],
        ['CONSOMMABLES', 'Consommables & Emballage', 9],
        ['HYGIENE', 'Hygiène & Entretien', 10],
    ];

    public function getDescription(): string
    {
        return 'Seed taxonomie produit (10 familles) dans categorie_produit';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CATEGORIES as [$code, $nom, $ordre]) {
            $this->addSql(
                'INSERT INTO categorie_produit (nom, code, ordre) VALUES (:nom, :code, :ordre) ON CONFLICT (code) DO NOTHING',
                ['nom' => $nom, 'code' => $code, 'ordre' => $ordre],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $codes = array_map(static fn (array $c): string => $c[0], self::CATEGORIES);
        // Détache les produits avant suppression (la FK produit.categorie_id n'est pas ON DELETE SET NULL côté schéma).
        $this->addSql(
            'UPDATE produit SET categorie_id = NULL WHERE categorie_id IN (SELECT id FROM categorie_produit WHERE code IN (:codes))',
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $this->addSql(
            'DELETE FROM categorie_produit WHERE code IN (:codes)',
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }
}
