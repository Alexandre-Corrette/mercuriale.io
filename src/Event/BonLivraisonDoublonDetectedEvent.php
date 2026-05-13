<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatché par le worker OCR quand un BL extrait s'avère être un doublon
 * (même fournisseur + même numéro BL qu'un BL existant pour l'établissement).
 *
 * Le BL doublon est supprimé physiquement par le worker juste après le dispatch.
 * Les listeners peuvent réagir : stocker une notification user, envoyer un mail
 * au manager, logger en audit, etc. — sans coupler ces side-effects au service OCR.
 */
final class BonLivraisonDoublonDetectedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly int $etablissementId,
        public readonly int $originalBonLivraisonId,
        public readonly string $numeroBl,
    ) {
    }
}
