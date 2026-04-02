<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260402100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing Unite records for OCR BL processing (PU, KG, COL, BOT, etc.)';
    }

    public function up(Schema $schema): void
    {
        $unites = [
            ['PU', 'Pièce unitaire', 'quantite', 1],
            ['KG', 'Kilogramme', 'poids', 2],
            ['G', 'Gramme', 'poids', 3],
            ['L', 'Litre', 'volume', 4],
            ['CL', 'Centilitre', 'volume', 5],
            ['COL', 'Colis', 'quantite', 6],
            ['BOT', 'Bouteille', 'quantite', 7],
            ['BQT', 'Barquette', 'quantite', 8],
            ['SAC', 'Sac', 'quantite', 9],
            ['CAR', 'Carton', 'quantite', 10],
            ['FUT', 'Fût', 'quantite', 11],
            ['UNI', 'Unité', 'quantite', 12],
            ['BOI', 'Boîte', 'quantite', 13],
            ['FLT', 'Filet', 'quantite', 14],
            ['PLQ', 'Plaque', 'quantite', 15],
            ['BAR', 'Barril', 'volume', 16],
            ['KM', 'Kilomètre', 'longueur', 17],
        ];

        foreach ($unites as [$code, $nom, $type, $ordre]) {
            $this->addSql(
                'INSERT INTO unite (nom, code, type, ordre) VALUES (:nom, :code, :type, :ordre) ON CONFLICT (code) DO NOTHING',
                ['nom' => $nom, 'code' => $code, 'type' => $type, 'ordre' => $ordre],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $codes = ['PU', 'KG', 'G', 'L', 'CL', 'COL', 'BOT', 'BQT', 'SAC', 'CAR', 'FUT', 'UNI', 'BOI', 'FLT', 'PLQ', 'BAR', 'KM'];

        foreach ($codes as $code) {
            $this->addSql('DELETE FROM unite WHERE code = :code', ['code' => $code]);
        }
    }
}
