<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\MercurialeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MercurialeRepository::class)]
#[ORM\Table(name: 'mercuriale')]
#[ORM\Index(columns: ['produit_fournisseur_id'], name: 'idx_mercuriale_produit_fournisseur')]
#[ORM\Index(columns: ['etablissement_id'], name: 'idx_mercuriale_etablissement')]
#[ORM\Index(columns: ['date_debut', 'date_fin'], name: 'idx_mercuriale_dates')]
#[ORM\HasLifecycleCallbacks]
class Mercuriale
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProduitFournisseur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Le produit fournisseur est obligatoire')]
    private ?ProduitFournisseur $produitFournisseur = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    #[Assert\NotNull(message: 'Le prix négocié est obligatoire')]
    #[Assert\Positive(message: 'Le prix doit être positif')]
    private ?string $prixNegocie = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date de début est obligatoire')]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '5.00'])]
    #[Assert\PositiveOrZero(message: 'Le seuil d\'alerte doit être positif ou nul')]
    private string $seuilAlertePct = '5.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $createdBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduitFournisseur(): ?ProduitFournisseur
    {
        return $this->produitFournisseur;
    }

    public function setProduitFournisseur(?ProduitFournisseur $produitFournisseur): static
    {
        $this->produitFournisseur = $produitFournisseur;

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): static
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    public function getPrixNegocie(): ?string
    {
        return $this->prixNegocie;
    }

    public function setPrixNegocie(string $prixNegocie): static
    {
        $this->prixNegocie = $prixNegocie;

        return $this;
    }

    public function getPrixNegocieAsFloat(): float
    {
        return (float) $this->prixNegocie;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getSeuilAlertePct(): string
    {
        return $this->seuilAlertePct;
    }

    public function setSeuilAlertePct(string $seuilAlertePct): static
    {
        $this->seuilAlertePct = $seuilAlertePct;

        return $this;
    }

    public function getSeuilAlertePctAsFloat(): float
    {
        return (float) $this->seuilAlertePct;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedBy(): ?Utilisateur
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?Utilisateur $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function isActif(?\DateTimeImmutable $date = null): bool
    {
        $date = $date ?? new \DateTimeImmutable();

        if ($this->dateDebut > $date) {
            return false;
        }

        if ($this->dateFin !== null && $this->dateFin < $date) {
            return false;
        }

        return true;
    }

    public function isPrixGroupe(): bool
    {
        return $this->etablissement === null;
    }
}
