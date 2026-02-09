<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Utilisateur;
use App\Repository\PushSubscriptionRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

class PushNotificationService
{
    private ?WebPush $webPush = null;

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptionRepository,
        private readonly LoggerInterface $logger,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
    ) {
    }

    public function sendToUser(Utilisateur $user, string $title, string $body, ?string $url = null): int
    {
        $subscriptions = $this->subscriptionRepository->findByUser($user);

        if (empty($subscriptions)) {
            return 0;
        }

        return $this->sendNotifications($subscriptions, $title, $body, $url);
    }

    /**
     * @param Utilisateur[] $users
     */
    public function sendToUsers(array $users, string $title, string $body, ?string $url = null): int
    {
        $userIds = array_map(fn (Utilisateur $u) => $u->getId(), $users);
        $subscriptions = $this->subscriptionRepository->findByUsers($userIds);

        if (empty($subscriptions)) {
            return 0;
        }

        return $this->sendNotifications($subscriptions, $title, $body, $url);
    }

    /**
     * @param \App\Entity\PushSubscription[] $pushSubscriptions
     */
    private function sendNotifications(array $pushSubscriptions, string $title, string $body, ?string $url): int
    {
        $webPush = $this->getWebPush();
        $sent = 0;

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url ?? '/',
            'icon' => '/icons/icon-192x192.png',
            'badge' => '/icons/icon-192x192.png',
            'tag' => 'mercuriale-' . substr(md5($title . $body), 0, 8),
        ], \JSON_THROW_ON_ERROR);

        foreach ($pushSubscriptions as $pushSubscription) {
            $subscription = Subscription::create([
                'endpoint' => $pushSubscription->getEndpoint(),
                'publicKey' => $pushSubscription->getP256dhKey(),
                'authToken' => $pushSubscription->getAuthToken(),
            ]);

            $webPush->queueNotification($subscription, $payload);
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();

            if ($report->isSuccess()) {
                $sent++;
            } else {
                $statusCode = $report->getResponse()?->getStatusCode();
                $this->logger->warning('Push notification failed', [
                    'endpoint' => substr($endpoint, 0, 80) . '…',
                    'reason' => $report->getReason(),
                    'status' => $statusCode,
                ]);

                // 410 Gone or 404 Not Found = stale subscription, auto-delete
                if ($statusCode === 410 || $statusCode === 404) {
                    $this->subscriptionRepository->deleteByEndpoint($endpoint);
                    $this->logger->info('Stale push subscription deleted', [
                        'endpoint' => substr($endpoint, 0, 80) . '…',
                    ]);
                }
            }
        }

        return $sent;
    }

    private function getWebPush(): WebPush
    {
        if ($this->webPush === null) {
            $this->webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->vapidSubject,
                    'publicKey' => $this->vapidPublicKey,
                    'privateKey' => $this->vapidPrivateKey,
                ],
            ]);

            // Do not throw on failure — we handle reports in the loop
            $this->webPush->setReuseVAPIDHeaders(true);
        }

        return $this->webPush;
    }
}
