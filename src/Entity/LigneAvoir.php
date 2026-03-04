<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LigneAvoirRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LigneAvoirRepository::class)]
#[ORM\Table(name: 'ligne_avoir')]
#[ORM\Index(columns: ['avoir_id'], name: 'idx_ligne_avoir')]
class LigneAvoir
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AvoirFournisseur::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'L\'avoir est obligatoire')]
    private ?AvoirFournisseur $avoir = null;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Produit $produit = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'La désignation est obligatoire')]
    #[Assert\Length(max: 255, maxMessage: 'La désignation ne peut pas dépasser {{ limit }} caractères')]
    private ?string $designation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3)]
    #[Assert\NotNull(message: 'La quantité est obligatoire')]
    #[Assert\PositiveOrZero(message: 'La quantité doit être positive ou nulle')]
    private ?string $quantite = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    #[Assert\NotNull(message: 'Le prix unitaire est obligatoire')]
    #[Assert\PositiveOrZero(message: 'Le prix unitaire doit être positif ou nul')]
    private ?string $prixUnitaire = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotNull(message: 'Le montant ligne est obligatoire')]
    #[Assert\PositiveOrZero(message: 'Le montant ligne doit être positif ou nul')]
    private ?string $montantLigne = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAvoir(): ?AvoirFournisseur
    {
        return $this->avoir;
    }

    public function setAvoir(?AvoirFournisseur $avoir): static
    {
        $this->avoir = $avoir;

        return $this;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): static
    {
        $this->produit = $produit;

        return $this;
    }

    public function getDesignation(): ?string
    {
        return $this->designation;
    }

    public function setDesignation(string $designation): static
    {
        $this->designation = $designation;

        return $this;
    }

    public function getQuantite(): ?string
    {
        return $this->quantite;
    }

    public function setQuantite(string $quantite): static
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getPrixUnitaire(): ?string
    {
        return $this->prixUnitaire;
    }

    public function setPrixUnitaire(string $prixUnitaire): static
    {
        $this->prixUnitaire = $prixUnitaire;

        return $this;
    }

    public function getMontantLigne(): ?string
    {
        return $this->montantLigne;
    }

    public function setMontantLigne(string $montantLigne): static
    {
        $this->montantLigne = $montantLigne;

        return $this;
    }

    public function calculerMontantLigne(): string
    {
        if ($this->quantite === null || $this->prixUnitaire === null) {
            return '0';
        }

        return bcmul($this->quantite, $this->prixUnitaire, 2);
    }
}
