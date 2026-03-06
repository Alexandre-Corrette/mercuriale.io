<?php

declare(strict_types=1);

namespace App\Service\Stripe;

use App\Entity\Organisation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;

class StripeConnectService
{
    private readonly StripeClient $stripe;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        string $stripeSecretKey,
    ) {
        $this->stripe = new StripeClient($stripeSecretKey);
    }

    public function createAccountAfterVerification(string $verificationSessionId): void
    {
        $orgRepo = $this->em->getRepository(Organisation::class);
        $organisation = $orgRepo->findOneBy(['stripeVerificationSessionId' => $verificationSessionId]);

        if ($organisation === null) {
            $this->logger->warning('Organisation not found for Connect account creation', [
                'session_id' => $verificationSessionId,
            ]);

            return;
        }

        if ($organisation->getStripeAccountId() !== null) {
            $this->logger->info('Stripe Connect account already exists', [
                'organisation_id' => $organisation->getId(),
                'account_id' => $organisation->getStripeAccountId(),
            ]);

            return;
        }

        try {
            $account = $this->stripe->accounts->create([
                'type' => 'standard',
                'country' => 'FR',
                'metadata' => [
                    'organisation_id' => (string) $organisation->getId(),
                ],
                'business_profile' => [
                    'name' => $organisation->getNom(),
                ],
            ]);

            $organisation->setStripeAccountId($account->id);
            $this->em->flush();

            $this->logger->info('Stripe Connect account created', [
                'organisation_id' => $organisation->getId(),
                'account_id' => $account->id,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create Stripe Connect account', [
                'organisation_id' => $organisation->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
