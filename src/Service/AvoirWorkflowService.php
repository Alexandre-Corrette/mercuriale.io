<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvoirFournisseur;
use App\Entity\Utilisateur;
use App\Enum\StatutAvoir;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AvoirWorkflowService
{
    private const TRANSITIONS = [
        'DEMANDE' => ['RECU', 'REFUSE', 'ANNULE'],
        'RECU' => ['IMPUTE', 'ANNULE'],
        'IMPUTE' => [],
        'REFUSE' => [],
        'ANNULE' => [],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enregistrer(
        AvoirFournisseur $avoir,
        string $reference,
        Utilisateur $user,
        ?string $montantHt = null,
        ?string $montantTva = null,
        ?string $montantTtc = null,
    ): void {
        $this->assertTransition($avoir, StatutAvoir::RECU);

        if ($reference === '') {
            throw new \InvalidArgumentException('La référence de l\'avoir est obligatoire.');
        }

        $avoir->setStatut(StatutAvoir::RECU);
        $avoir->setReference($reference);
        $avoir->setRecuLe(new \DateTimeImmutable());
        $avoir->setValidatedBy($user);

        if ($montantHt !== null && $montantHt !== '') {
            $avoir->setMontantHt($montantHt);
        }
        if ($montantTva !== null && $montantTva !== '') {
            $avoir->setMontantTva($montantTva);
        }
        if ($montantTtc !== null && $montantTtc !== '') {
            $avoir->setMontantTtc($montantTtc);
        }

        $this->entityManager->flush();

        $this->logger->info('Avoir enregistré (reçu)', [
            'avoir_id' => $avoir->getIdAsString(),
            'reference' => $reference,
            'montant_ht' => $avoir->getMontantHt(),
            'user_id' => $user->getId(),
        ]);
    }

    public function imputer(AvoirFournisseur $avoir, Utilisateur $user): void
    {
        $this->assertTransition($avoir, StatutAvoir::IMPUTE);

        if ($avoir->getMontantHt() === null || bccomp($avoir->getMontantHt(), '0', 2) <= 0) {
            throw new \InvalidArgumentException('Le montant HT doit être supérieur à 0 pour imputer.');
        }

        $avoir->setStatut(StatutAvoir::IMPUTE);
        $avoir->setValidatedBy($user);
        $avoir->setImputeLe(new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->logger->info('Avoir imputé', [
            'avoir_id' => $avoir->getIdAsString(),
            'reference' => $avoir->getReference(),
            'montant_ht' => $avoir->getMontantHt(),
            'user_id' => $user->getId(),
        ]);
    }

    public function refuser(AvoirFournisseur $avoir, string $commentaire, Utilisateur $user): void
    {
        $this->assertTransition($avoir, StatutAvoir::REFUSE);

        if (trim($commentaire) === '') {
            throw new \InvalidArgumentException('Le motif du refus est obligatoire.');
        }

        $avoir->setStatut(StatutAvoir::REFUSE);
        $avoir->setCommentaire($commentaire);

        $this->entityManager->flush();

        $this->logger->info('Avoir refusé', [
            'avoir_id' => $avoir->getIdAsString(),
            'reference' => $avoir->getReference(),
            'user_id' => $user->getId(),
        ]);
    }

    public function annuler(AvoirFournisseur $avoir, string $commentaire, Utilisateur $user): void
    {
        $this->assertTransition($avoir, StatutAvoir::ANNULE);

        if (trim($commentaire) === '') {
            throw new \InvalidArgumentException('Le motif de l\'annulation est obligatoire.');
        }

        $avoir->setStatut(StatutAvoir::ANNULE);
        $avoir->setCommentaire($commentaire);

        $this->entityManager->flush();

        $this->logger->info('Avoir annulé', [
            'avoir_id' => $avoir->getIdAsString(),
            'reference' => $avoir->getReference(),
            'user_id' => $user->getId(),
        ]);
    }

    public function canTransition(AvoirFournisseur $avoir, StatutAvoir $target): bool
    {
        $allowed = self::TRANSITIONS[$avoir->getStatut()->value] ?? [];

        return \in_array($target->value, $allowed, true);
    }

    private function assertTransition(AvoirFournisseur $avoir, StatutAvoir $target): void
    {
        if (!$this->canTransition($avoir, $target)) {
            throw new \LogicException(\sprintf(
                'Transition invalide : %s → %s pour l\'avoir %s.',
                $avoir->getStatut()->value,
                $target->value,
                $avoir->getIdAsString(),
            ));
        }
    }
}
