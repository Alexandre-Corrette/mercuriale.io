<?php

declare(strict_types=1);

namespace App\Controller\Webhook;

use App\Service\Stripe\StripeConnectService;
use App\Service\Stripe\StripeIdentityService;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeIdentityService $identityService,
        private readonly StripeConnectService $connectService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhook/stripe', name: 'webhook_stripe', methods: ['POST'])]
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature');
        $webhookSecret = $this->getParameter('stripe_webhook_secret');

        if ($sigHeader === null || $webhookSecret === '') {
            return new JsonResponse(['error' => 'Missing signature'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            $this->logger->warning('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Stripe webhook received', [
            'type' => $event->type,
            'id' => $event->id,
        ]);

        match ($event->type) {
            'identity.verification_session.verified' => $this->handleVerified($event),
            'identity.verification_session.requires_input' => $this->handleRequiresInput($event),
            default => $this->logger->debug('Unhandled Stripe event', ['type' => $event->type]),
        };

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleVerified(object $event): void
    {
        $sessionId = $event->data->object->id ?? null;
        if ($sessionId === null) {
            return;
        }

        $this->identityService->handleVerificationCompleted($sessionId);

        // Auto-create Stripe Connect account after verification
        $this->connectService->createAccountAfterVerification($sessionId);
    }

    private function handleRequiresInput(object $event): void
    {
        $sessionId = $event->data->object->id ?? null;
        if ($sessionId === null) {
            return;
        }

        $this->identityService->handleVerificationFailed($sessionId);
    }
}
