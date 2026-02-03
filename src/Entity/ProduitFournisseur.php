<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\ProduitFournisseurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProduitFournisseurRepository::class)]
#[ORM\Table(name: 'produit_fournisseur')]
#[ORM\UniqueConstraint(name: 'unique_fournisseur_code', columns: ['fournisseur_id', 'code_fournisseur'])]
#[ORM\Index(columns: ['fournisseur_id'], name: 'idx_produit_fournisseur_fournisseur')]
#[ORM\Index(columns: ['produit_id'], name: 'idx_produit_fournisseur_produit')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['fournisseur', 'codeFournisseur'], message: 'Ce code produit existe déjà pour ce fournisseur')]
class ProduitFournisseur
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Produit::class, inversedBy: 'produitsFournisseur')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Produit $produit = null;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class, inversedBy: 'produitsFournisseur')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Le fournisseur est obligatoire')]
    private ?Fournisseur $fournisseur = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le code fournisseur est obligatoire')]
    #[Assert\Length(max: 50, maxMessage: 'Le code ne peut pas dépasser {{ limit }} caractères')]
    private ?string $codeFournisseur = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'La désignation fournisseur est obligatoire')]
    #[Assert\Length(max: 255, maxMessage: 'La désignation ne peut pas dépasser {{ limit }} caractères')]
    private ?string $designationFournisseur = null;

    #[ORM\ManyToOne(targetEntity: Unite::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'unité d\'achat est obligatoire')]
    private ?Unite $uniteAchat = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3, options: ['default' => '1.000'])]
    #[Assert\Positive(message: 'Le conditionnement doit être positif')]
    private string $conditionnement = '1.000';

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getCodeFournisseur(): ?string
    {
        return $this->codeFournisseur;
    }

    public function setCodeFournisseur(string $codeFournisseur): static
    {
        $this->codeFournisseur = $codeFournisseur;

        return $this;
    }

    public function getDesignationFournisseur(): ?string
    {
        return $this->designationFournisseur;
    }

    public function setDesignationFournisseur(string $designationFournisseur): static
    {
        $this->designationFournisseur = $designationFournisseur;

        return $this;
    }

    public function getUniteAchat(): ?Unite
    {
        return $this->uniteAchat;
    }

    public function setUniteAchat(?Unite $uniteAchat): static
    {
        $this->uniteAchat = $uniteAchat;

        return $this;
    }

    public function getConditionnement(): string
    {
        return $this->conditionnement;
    }

    public function setConditionnement(string $conditionnement): static
    {
        $this->conditionnement = $conditionnement;

        return $this;
    }

    public function getConditionnementAsFloat(): float
    {
        return (float) $this->conditionnement;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function __toString(): string
    {
        return $this->designationFournisseur ?? $this->codeFournisseur ?? '';
    }
}
