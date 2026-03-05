<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

use App\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles the onboarding flow for an establishment on the PDP (B2Brouter).
 *
 * Sequence:
 * 1. registerCompany() → PDP account ID
 * 2. enableReception() → activate transports (B2Brouter + Peppol)
 * 3. Mark etablissement as e-invoicing enabled
 */
class EInvoicingOnboardingService
{
    public function __construct(
        private readonly PdpClientInterface $pdpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Registers an establishment on the PDP and enables invoice reception.
     *
     * @throws PdpApiException      on API failure
     * @throws \InvalidArgumentException on missing required data
     */
    public function onboard(Etablissement $etablissement, string $vatNumber): void
    {
        if ($etablissement->isEInvoicingEnabled()) {
            throw new \LogicException(sprintf(
                'L\'établissement "%s" est déjà inscrit à la facturation électronique.',
                $etablissement->getNom(),
            ));
        }

        $this->validateVatNumber($vatNumber);

        $organisation = $etablissement->getOrganisation();
        if ($organisation === null) {
            throw new \InvalidArgumentException('L\'établissement doit être rattaché à une organisation.');
        }

        // 1. Register company on PDP
        $accountId = $this->pdpClient->registerCompany(
            vatNumber: $vatNumber,
            companyName: $organisation->getNom() ?? $etablissement->getNom(),
            address: $etablissement->getAdresse() ?? '',
            postalCode: $etablissement->getCodePostal() ?? '',
            city: $etablissement->getVille() ?? '',
            country: 'FR',
        );

        // 2. Enable reception transports
        $this->pdpClient->enableReception($accountId);

        // 3. Update establishment
        $this->entityManager->wrapInTransaction(function () use ($etablissement, $accountId): void {
            $etablissement->setPdpAccountId($accountId);
            $etablissement->setEInvoicingEnabled(true);
            $etablissement->setEInvoicingEnabledAt(new \DateTimeImmutable());
        });

        $this->logger->info('[EInvoicing] Établissement inscrit sur la PDP', [
            'etablissement_id' => $etablissement->getId(),
            'etablissement_nom' => $etablissement->getNom(),
            'pdp_account_id' => $accountId,
        ]);
    }

    /**
     * Validates a French intra-community VAT number format.
     *
     * @throws \InvalidArgumentException on invalid format
     */
    private function validateVatNumber(string $vatNumber): void
    {
        $cleaned = strtoupper(str_replace(' ', '', $vatNumber));

        if (!preg_match('/^FR\d{2}\d{9}$/', $cleaned)) {
            throw new \InvalidArgumentException(sprintf(
                'Numéro de TVA intracommunautaire invalide : "%s". Format attendu : FRXX999999999.',
                $vatNumber,
            ));
        }
    }
}
