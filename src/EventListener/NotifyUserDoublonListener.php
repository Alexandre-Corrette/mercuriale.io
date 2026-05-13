<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\BonLivraisonDoublonDetectedEvent;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Réagit à la détection d'un BL doublon par le worker OCR.
 * Stocke une notification ciblée user_id dans le cache (TTL 5 min) que le
 * frontend récupère ensuite via /api/notifications/pending.
 *
 * Le cache pool `app` par défaut est filesystem mais partagé entre les
 * containers Docker via le volume monté sur /var/www/html.
 */
#[AsEventListener(event: BonLivraisonDoublonDetectedEvent::class, method: '__invoke')]
final class NotifyUserDoublonListener
{
    private const NOTIF_TTL_SECONDS = 300; // 5 min

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function __invoke(BonLivraisonDoublonDetectedEvent $event): void
    {
        $item = $this->cache->getItem(self::cacheKey($event->userId));
        $item->set([
            'type' => 'doublon',
            'message' => sprintf(
                'Ce bon de livraison a déjà été transmis (BL #%d, numéro %s). L\'upload a été supprimé.',
                $event->originalBonLivraisonId,
                $event->numeroBl,
            ),
            'original_bl_id' => $event->originalBonLivraisonId,
            'created_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
        $item->expiresAfter(self::NOTIF_TTL_SECONDS);
        $this->cache->save($item);
    }

    public static function cacheKey(int $userId): string
    {
        return 'user_notif_pending_' . $userId;
    }
}
