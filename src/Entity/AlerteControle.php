<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatutAlerte;
use App\Enum\TypeAlerte;
use App\Repository\AlerteControleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AlerteControleRepository::class)]
#[ORM\Table(name: 'alerte_controle')]
#[ORM\Index(columns: ['ligne_bl_id'], name: 'idx_alerte_ligne')]
#[ORM\Index(columns: ['statut'], name: 'idx_alerte_statut')]
#[ORM\HasLifecycleCallbacks]
class AlerteControle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LigneBonLivraison::class, inversedBy: 'alertes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'La ligne de BL est obligatoire')]
    private ?LigneBonLivraison $ligneBl = null;

    #[ORM\Column(length: 30, enumType: TypeAlerte::class)]
    #[Assert\NotNull(message: 'Le type d\'alerte est obligatoire')]
    private ?TypeAlerte $typeAlerte = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank(message: 'Le message est obligatoire')]
    #[Assert\Length(max: 500, maxMessage: 'Le message ne peut pas dépasser {{ limit }} caractères')]
    private ?string $message = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $valeurAttendue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $valeurRecue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $ecartPct = null;

    #[ORM\Column(length: 20, enumType: StatutAlerte::class, options: ['default' => 'NOUVELLE'])]
    private StatutAlerte $statut = StatutAlerte::NOUVELLE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $traiteeAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $traiteePar = null;

    #[ORM\PrePersist]
    public function initializeCreatedAt(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLigneBl(): ?LigneBonLivraison
    {
        return $this->ligneBl;
    }

    public function setLigneBl(?LigneBonLivraison $ligneBl): static
    {
        $this->ligneBl = $ligneBl;

        return $this;
    }

    public function getTypeAlerte(): ?TypeAlerte
    {
        return $this->typeAlerte;
    }

    public function setTypeAlerte(TypeAlerte $typeAlerte): static
    {
        $this->typeAlerte = $typeAlerte;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getValeurAttendue(): ?string
    {
        return $this->valeurAttendue;
    }

    public function setValeurAttendue(?string $valeurAttendue): static
    {
        $this->valeurAttendue = $valeurAttendue;

        return $this;
    }

    public function getValeurRecue(): ?string
    {
        return $this->valeurRecue;
    }

    public function setValeurRecue(?string $valeurRecue): static
    {
        $this->valeurRecue = $valeurRecue;

        return $this;
    }

    public function getEcartPct(): ?string
    {
        return $this->ecartPct;
    }

    public function setEcartPct(?string $ecartPct): static
    {
        $this->ecartPct = $ecartPct;

        return $this;
    }

    public function getEcartPctAsFloat(): ?float
    {
        return $this->ecartPct !== null ? (float) $this->ecartPct : null;
    }

    public function getStatut(): StatutAlerte
    {
        return $this->statut;
    }

    public function setStatut(StatutAlerte $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTraiteeAt(): ?\DateTimeImmutable
    {
        return $this->traiteeAt;
    }

    public function setTraiteeAt(?\DateTimeImmutable $traiteeAt): static
    {
        $this->traiteeAt = $traiteeAt;

        return $this;
    }

    public function getTraiteePar(): ?Utilisateur
    {
        return $this->traiteePar;
    }

    public function setTraiteePar(?Utilisateur $traiteePar): static
    {
        $this->traiteePar = $traiteePar;

        return $this;
    }

    public function traiter(Utilisateur $utilisateur, StatutAlerte $statut, ?string $commentaire = null): void
    {
        $this->statut = $statut;
        $this->traiteePar = $utilisateur;
        $this->traiteeAt = new \DateTimeImmutable();
        $this->commentaire = $commentaire;
    }

    public function isTraitee(): bool
    {
        return $this->statut === StatutAlerte::ACCEPTEE || $this->statut === StatutAlerte::REFUSEE;
    }
}
