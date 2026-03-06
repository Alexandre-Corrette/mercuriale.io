<?php

declare(strict_types=1);

namespace App\Service\Stripe;

use App\Entity\Organisation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;

class StripeIdentityService
{
    private readonly StripeClient $stripe;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        string $stripeSecretKey,
    ) {
        $this->stripe = new StripeClient($stripeSecretKey);
    }

    public function createVerificationSession(Organisation $organisation, string $returnUrl): string
    {
        $session = $this->stripe->identity->verificationSessions->create([
            'type' => 'document',
            'metadata' => [
                'organisation_id' => (string) $organisation->getId(),
            ],
            'options' => [
                'document' => [
                    'require_matching_selfie' => true,
                ],
            ],
            'return_url' => $returnUrl,
        ]);

        $organisation->setStripeVerificationSessionId($session->id);
        $this->em->flush();

        $this->logger->info('Stripe Identity session created', [
            'organisation_id' => $organisation->getId(),
            'session_id' => $session->id,
        ]);

        return $session->url;
    }

    public function handleVerificationCompleted(string $sessionId): void
    {
        $orgRepo = $this->em->getRepository(Organisation::class);
        $organisation = $orgRepo->findOneBy(['stripeVerificationSessionId' => $sessionId]);

        if ($organisation === null) {
            $this->logger->warning('Stripe verification session not found', ['session_id' => $sessionId]);

            return;
        }

        $organisation->setVerifiedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->logger->info('Organisation verified via Stripe Identity', [
            'organisation_id' => $organisation->getId(),
            'session_id' => $sessionId,
        ]);
    }

    public function handleVerificationFailed(string $sessionId): void
    {
        $this->logger->warning('Stripe Identity verification failed', [
            'session_id' => $sessionId,
        ]);
    }
}
