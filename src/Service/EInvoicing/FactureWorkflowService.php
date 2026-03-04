<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

use App\Entity\FactureFournisseur;
use App\Entity\Utilisateur;
use App\Enum\StatutFacture;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FactureWorkflowService
{
    private const TRANSITIONS = [
        'RECUE' => ['ACCEPTEE', 'REFUSEE'],
        'ACCEPTEE' => ['PAYEE'],
        'RAPPROCHEE' => ['ACCEPTEE', 'REFUSEE'],
        'REFUSEE' => [],
        'PAYEE' => [],
    ];

    public function __construct(
        private readonly PdpClientInterface $pdpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function accepter(FactureFournisseur $facture, Utilisateur $user): void
    {
        $this->assertTransition($facture, StatutFacture::ACCEPTEE);

        $this->entityManager->wrapInTransaction(function () use ($facture, $user): void {
            $facture->setStatut(StatutFacture::ACCEPTEE);
            $facture->setAccepteeLe(new \DateTimeImmutable());
            $facture->setValidatedBy($user);
        });

        // Sync with B2Brouter
        $this->syncStatus($facture, 'accepted');

        $this->logger->info('[EInvoicing] Facture acceptée', [
            'facture_id' => $facture->getIdAsString(),
            'numero' => $facture->getNumeroFacture(),
            'user_id' => $user->getId(),
        ]);
    }

    public function refuser(FactureFournisseur $facture, string $motif, Utilisateur $user): void
    {
        $this->assertTransition($facture, StatutFacture::REFUSEE);

        if (trim($motif) === '') {
            throw new \InvalidArgumentException('Le motif du refus est obligatoire.');
        }

        $this->entityManager->wrapInTransaction(function () use ($facture, $motif, $user): void {
            $facture->setStatut(StatutFacture::REFUSEE);
            $facture->setMotifRefus($motif);
            $facture->setValidatedBy($user);
        });

        // Sync with B2Brouter
        $this->syncStatus($facture, 'refused', $motif);

        $this->logger->info('[EInvoicing] Facture refusée', [
            'facture_id' => $facture->getIdAsString(),
            'numero' => $facture->getNumeroFacture(),
            'motif' => $motif,
            'user_id' => $user->getId(),
        ]);
    }

    public function marquerPayee(FactureFournisseur $facture, Utilisateur $user): void
    {
        $this->assertTransition($facture, StatutFacture::PAYEE);

        $this->entityManager->wrapInTransaction(function () use ($facture, $user): void {
            $facture->setStatut(StatutFacture::PAYEE);
            $facture->setPayeeLe(new \DateTimeImmutable());
            $facture->setValidatedBy($user);
        });

        // Sync with B2Brouter
        $this->syncStatus($facture, 'paid');

        $this->logger->info('[EInvoicing] Facture marquée payée', [
            'facture_id' => $facture->getIdAsString(),
            'numero' => $facture->getNumeroFacture(),
            'user_id' => $user->getId(),
        ]);
    }

    public function canTransition(FactureFournisseur $facture, StatutFacture $target): bool
    {
        $allowed = self::TRANSITIONS[$facture->getStatut()->value] ?? [];

        return \in_array($target->value, $allowed, true);
    }

    private function assertTransition(FactureFournisseur $facture, StatutFacture $target): void
    {
        if (!$this->canTransition($facture, $target)) {
            throw new \LogicException(sprintf(
                'Transition invalide : %s → %s pour la facture %s.',
                $facture->getStatut()->value,
                $target->value,
                $facture->getIdAsString(),
            ));
        }
    }

    private function syncStatus(FactureFournisseur $facture, string $b2bStatus, ?string $reason = null): void
    {
        $externalId = $facture->getExternalId();
        if ($externalId === null) {
            return;
        }

        try {
            $this->pdpClient->updateInvoiceStatus($externalId, $b2bStatus, $reason);
        } catch (PdpApiException $e) {
            // Log but don't fail — local status is already updated
            $this->logger->warning('[EInvoicing] Échec synchronisation statut B2Brouter', [
                'facture_id' => $facture->getIdAsString(),
                'b2b_status' => $b2bStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
