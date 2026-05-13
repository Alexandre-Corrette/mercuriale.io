<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Utilisateur;
use App\EventListener\NotifyUserDoublonListener;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Renvoie la notification en attente pour l'user courant (et la clear).
     * Utilisé par le frontend pour récupérer les messages dispatchés par le worker
     * (ex. doublon détecté à l'OCR) qui n'ont pas pu transiter par la session HTTP.
     */
    #[Route('/app/notifications/pending', name: 'app_notification_pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $key = NotifyUserDoublonListener::cacheKey($user->getId());

        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return new JsonResponse(['notification' => null]);
        }

        $notification = $item->get();
        $this->cache->deleteItem($key);

        return new JsonResponse(['notification' => $notification]);
    }
}
