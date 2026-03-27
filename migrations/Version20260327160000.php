<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MERC-140: Add missing Unite records for mercuriale import tolerance';
    }

    public function up(Schema $schema): void
    {
        $units = [
            ['Boîte', 'BTE', 'quantite', 29],
            ['Plateau', 'PLT', 'quantite', 30],
            ['Palette', 'PAL', 'quantite', 31],
            ['Pack', 'PCK', 'quantite', 32],
            ['Bidon', 'BDN', 'quantite', 33],
            ['Jerrycan', 'JER', 'quantite', 34],
            ['Bocal', 'BOC', 'quantite', 35],
            ['Bombe', 'BMB', 'quantite', 36],
            ['Étui', 'ETU', 'quantite', 37],
            ['Brick', 'BRK', 'quantite', 38],
            ['Flacon', 'FLA', 'quantite', 39],
            ['Poche', 'POC', 'quantite', 40],
            ['Pot', 'POT', 'quantite', 41],
            ['Rouleau', 'RLX', 'quantite', 42],
            ['Seau', 'SEA', 'quantite', 43],
            ['Tube', 'TUB', 'quantite', 44],
            ['Paquet', 'PQT', 'quantite', 45],
            ['Sachet', 'SCH', 'quantite', 46],
        ];

        foreach ($units as [$nom, $code, $type, $ordre]) {
            $this->addSql(
                'INSERT INTO unite (nom, code, type, ordre) VALUES (:nom, :code, :type, :ordre) ON CONFLICT (code) DO NOTHING',
                ['nom' => $nom, 'code' => $code, 'type' => $type, 'ordre' => $ordre],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $codes = ['BTE', 'PLT', 'PAL', 'PCK', 'BDN', 'JER', 'BOC', 'BMB', 'ETU', 'BRK', 'FLA', 'POC', 'POT', 'RLX', 'SEA', 'TUB', 'PQT', 'SCH'];

        foreach ($codes as $code) {
            $this->addSql('DELETE FROM unite WHERE code = :code', ['code' => $code]);
        }
    }
}
