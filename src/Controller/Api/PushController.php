<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\PushSubscription;
use App\Entity\Utilisateur;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/push')]
#[IsGranted('ROLE_USER')]
class PushController extends AbstractController
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactory $apiLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/subscribe', name: 'api_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Rate limiting
        $limiter = $this->apiLimiter->create('api_push_' . $user->getId());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(
                ['success' => false, 'error' => 'Trop de requêtes.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $data = json_decode($request->getContent(), true);

        $endpoint = $data['endpoint'] ?? null;
        $p256dh = $data['keys']['p256dh'] ?? null;
        $auth = $data['keys']['auth'] ?? null;

        if (!$endpoint || !$p256dh || !$auth) {
            return $this->json(
                ['success' => false, 'error' => 'Données de souscription incomplètes.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Upsert: update if endpoint already exists, otherwise create
        $subscription = $this->subscriptionRepository->findByEndpoint($endpoint);

        if ($subscription !== null) {
            $subscription->setUtilisateur($user);
            $subscription->setP256dhKey($p256dh);
            $subscription->setAuthToken($auth);
            $subscription->setUserAgent($request->headers->get('User-Agent'));
        } else {
            $subscription = new PushSubscription();
            $subscription->setUtilisateur($user);
            $subscription->setEndpoint($endpoint);
            $subscription->setP256dhKey($p256dh);
            $subscription->setAuthToken($auth);
            $subscription->setUserAgent($request->headers->get('User-Agent'));

            $this->entityManager->persist($subscription);
        }

        $this->entityManager->flush();

        $this->logger->info('Push subscription registered', [
            'user_id' => $user->getId(),
            'endpoint' => substr($endpoint, 0, 80) . '…',
        ]);

        return $this->json(['success' => true], Response::HTTP_CREATED);
    }

    #[Route('/unsubscribe', name: 'api_push_unsubscribe', methods: ['DELETE'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $endpoint = $data['endpoint'] ?? null;

        if (!$endpoint) {
            return $this->json(
                ['success' => false, 'error' => 'Endpoint manquant.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $this->subscriptionRepository->deleteByEndpoint($endpoint);

        $this->logger->info('Push subscription removed', [
            'user_id' => $user->getId(),
            'endpoint' => substr($endpoint, 0, 80) . '…',
        ]);

        return $this->json(['success' => true]);
    }
}
