<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FactureFournisseur;
use App\Entity\InvoiceSequence;
use App\Repository\InvoiceSequenceRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class InvoiceSequenceService
{
    public function __construct(
        private readonly InvoiceSequenceRepository $sequenceRepo,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function assignNextNumber(FactureFournisseur $facture): string
    {
        $etablissement = $facture->getEtablissement();
        if ($etablissement === null) {
            throw new \LogicException('La facture doit être rattachée à un établissement.');
        }

        $organisation = $etablissement->getOrganisation();
        if ($organisation === null) {
            throw new \LogicException('L\'établissement doit être rattaché à une organisation.');
        }

        $year = (int) date('Y');

        $sequence = $this->sequenceRepo->findOrCreateForOrganisation($organisation, $year, $this->entityManager);

        // Pessimistic lock to prevent duplicate numbers
        $lockedSequence = $this->entityManager->find(
            InvoiceSequence::class,
            $sequence->getId(),
            LockMode::PESSIMISTIC_WRITE
        );

        $nextNumber = $lockedSequence->incrementAndGet();
        $reference = $lockedSequence->formatNumber($nextNumber);

        $facture->setInternalReference($reference);

        $this->logger->info('[InvoiceSequence] Numéro attribué', [
            'facture_id' => $facture->getIdAsString(),
            'internal_reference' => $reference,
            'organisation_id' => $organisation->getId(),
            'year' => $year,
        ]);

        return $reference;
    }
}
