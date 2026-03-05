<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvoiceSequence;
use App\Entity\Organisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceSequence>
 */
class InvoiceSequenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceSequence::class);
    }

    public function findOrCreateForOrganisation(Organisation $organisation, int $year, EntityManagerInterface $em): InvoiceSequence
    {
        $sequence = $this->findOneBy([
            'organisation' => $organisation,
            'year' => $year,
        ]);

        if ($sequence === null) {
            $sequence = new InvoiceSequence($organisation, $year);
            $em->persist($sequence);
            $em->flush();
        }

        return $sequence;
    }

    public function findWithLock(InvoiceSequence $sequence, EntityManagerInterface $em): InvoiceSequence
    {
        return $em->find(InvoiceSequence::class, $sequence->getId(), LockMode::PESSIMISTIC_WRITE);
    }
}
