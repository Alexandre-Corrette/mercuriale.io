<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatutFacture;
use App\Repository\TransitionFactureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransitionFactureRepository::class)]
#[ORM\Table(name: 'transition_facture')]
#[ORM\Index(columns: ['facture_id'], name: 'idx_transition_facture')]
#[ORM\Index(columns: ['created_at'], name: 'idx_transition_created_at')]
class TransitionFacture
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: FactureFournisseur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FactureFournisseur $facture;

    #[ORM\Column(length: 20, enumType: StatutFacture::class)]
    private StatutFacture $fromStatut;

    #[ORM\Column(length: 20, enumType: StatutFacture::class)]
    private StatutFacture $toStatut;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $user;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motif = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        FactureFournisseur $facture,
        StatutFacture $fromStatut,
        StatutFacture $toStatut,
        Utilisateur $user,
        ?string $motif = null,
    ) {
        $this->id = Uuid::v4();
        $this->facture = $facture;
        $this->fromStatut = $fromStatut;
        $this->toStatut = $toStatut;
        $this->user = $user;
        $this->motif = $motif;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFacture(): FactureFournisseur
    {
        return $this->facture;
    }

    public function getFromStatut(): StatutFacture
    {
        return $this->fromStatut;
    }

    public function getToStatut(): StatutFacture
    {
        return $this->toStatut;
    }

    public function getUser(): Utilisateur
    {
        return $this->user;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
