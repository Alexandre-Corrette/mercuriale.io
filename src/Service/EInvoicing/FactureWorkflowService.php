<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

use App\Entity\FactureFournisseur;
use App\Entity\TransitionFacture;
use App\Entity\Utilisateur;
use App\Enum\StatutFacture;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FactureWorkflowService
{
    private const TRANSITIONS = [
        'RECUE' => ['ACCEPTEE', 'REFUSEE'],
        'ACCEPTEE' => ['PAYEE', 'CONTESTEE'],
        'RAPPROCHEE' => ['ACCEPTEE', 'REFUSEE'],
        'REFUSEE' => ['RECUE'],
        'PAYEE' => [],
        'CONTESTEE' => ['ACCEPTEE'],
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

        $fromStatut = $facture->getStatut();

        $this->entityManager->wrapInTransaction(function () use ($facture, $user, $fromStatut): void {
            $facture->setStatut(StatutFacture::ACCEPTEE);
            $facture->setAccepteeLe(new \DateTimeImmutable());
            $facture->setValidatedBy($user);

            $this->recordTransition($facture, $fromStatut, StatutFacture::ACCEPTEE, $user);
        });

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

        $fromStatut = $facture->getStatut();

        $this->entityManager->wrapInTransaction(function () use ($facture, $motif, $user, $fromStatut): void {
            $facture->setStatut(StatutFacture::REFUSEE);
            $facture->setMotifRefus($motif);
            $facture->setValidatedBy($user);

            $this->recordTransition($facture, $fromStatut, StatutFacture::REFUSEE, $user, $motif);
        });

        $this->syncStatus($facture, 'refused', $motif);

        $this->logger->info('[EInvoicing] Facture refusée', [
            'facture_id' => $facture->getIdAsString(),
            'numero' => $facture->getNumeroFacture(),
            'motif' => $motif,
            'user_id' => $user->getId(),
        ]);
    }

    public function marquerPayee(FactureFournisseur $facture, Utilisateur $user, ?string $referencePaiement = null): void
    {
        $this->assertTransition($facture, StatutFacture::PAYEE);

        $fromStatut = $facture->getStatut();

        $this->entityManager->wrapInTransaction(function () use ($facture, $user, $referencePaiement, $fromStatut): void {
            $facture->setStatut(StatutFacture::PAYEE);
            $facture->setPayeeLe(new \DateTimeImmutable());
            $facture->setValidatedBy($user);

            if ($referencePaiement !== null && $referencePaiement !== '') {
                $facture->setReferencePaiement($referencePaiement);
            }

            $this->recordTransition($facture, $fromStatut, StatutFacture::PAYEE, $user);
        });

        $this->syncStatus($facture, 'paid');

        $this->logger->info('[EInvoicing] Facture marquée payée', [
            'facture_id' => $facture->getIdAsString(),
            'numero' => $facture->getNumeroFacture(),
            'user_id' => $user->getId(),
        ]);
    }

    public function contester(FactureFournisseur $facture, string $motif, Utilisateur $user): void
    {
        $this->assertTransition($facture, StatutFacture::CONTESTEE);

        if (trim($motif) === '') {
            throw new \InvalidArgumentException('Le motif de la contestation est obligatoire.');
        }

        $fromStatut = $facture->getStatut();

        $this->entityManager->wrapInTransaction(function () use ($facture, $motif, $user, $fromStatut): void {
            $facture->setStatut(StatutFacture::CONTESTEE);
            $facture->setContesteeLe(new \DateTimeImmutable());
            $facture->setCommentaire($motif);

            $this->recordTransition($facture, $fromStatut, StatutFacture::CONTESTEE, $user, $motif);
        });

        $this->syncStatus($facture, 'disputed', $motif);

        $this->logger->info('[EInvoicing] Facture contestée', [
            'facture_id' => $facture->getIdAsString(),
            'numero' => $facture->getNumeroFacture(),
            'motif' => $motif,
            'user_id' => $user->getId(),
        ]);
    }

    public function remettre(FactureFournisseur $facture, Utilisateur $user): void
    {
        if ($facture->getStatut() !== StatutFacture::REFUSEE) {
            throw new \LogicException('Seule une facture refusée peut être remise en réception.');
        }

        $fromStatut = $facture->getStatut();

        $this->entityManager->wrapInTransaction(function () use ($facture, $user, $fromStatut): void {
            $facture->setStatut(StatutFacture::RECUE);
            $facture->setMotifRefus(null);

            $this->recordTransition($facture, $fromStatut, StatutFacture::RECUE, $user, 'Remise en réception');
        });

        $this->logger->info('[EInvoicing] Facture remise en réception', [
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

    private function recordTransition(
        FactureFournisseur $facture,
        StatutFacture $from,
        StatutFacture $to,
        Utilisateur $user,
        ?string $motif = null,
    ): void {
        $transition = new TransitionFacture($facture, $from, $to, $user, $motif);
        $this->entityManager->persist($transition);
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
            $this->logger->warning('[EInvoicing] Échec synchronisation statut B2Brouter', [
                'facture_id' => $facture->getIdAsString(),
                'b2b_status' => $b2bStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
