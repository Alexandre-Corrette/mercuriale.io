<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailContactFournisseur;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailContactFournisseur>
 */
class EmailContactFournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailContactFournisseur::class);
    }

    public function countSentLastHourByUser(Utilisateur $user): int
    {
        $oneHourAgo = new \DateTimeImmutable('-1 hour');

        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.sentBy = :user')
            ->andWhere('e.sentAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $oneHourAgo)
            ->getQuery()
            ->getSingleScalarResult();
    }
}