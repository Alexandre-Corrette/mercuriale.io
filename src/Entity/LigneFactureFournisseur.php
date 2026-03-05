<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LigneFactureFournisseurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LigneFactureFournisseurRepository::class)]
#[ORM\Table(name: 'ligne_facture_fournisseur')]
#[ORM\Index(columns: ['facture_id'], name: 'idx_ligne_facture')]
class LigneFactureFournisseur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FactureFournisseur::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?FactureFournisseur $facture = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $codeArticle = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'La désignation est obligatoire')]
    private ?string $designation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3)]
    #[Assert\NotNull]
    private ?string $quantite = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    #[Assert\NotNull]
    private ?string $prixUnitaire = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotNull]
    private ?string $montantLigne = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $tauxTva = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $unite = null;

    #[ORM\ManyToOne(targetEntity: ProduitFournisseur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProduitFournisseur $produit = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFacture(): ?FactureFournisseur
    {
        return $this->facture;
    }

    public function setFacture(?FactureFournisseur $facture): static
    {
        $this->facture = $facture;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getCodeArticle(): ?string
    {
        return $this->codeArticle;
    }

    public function setCodeArticle(?string $codeArticle): static
    {
        $this->codeArticle = $codeArticle;

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

    public function getTauxTva(): ?string
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?string $tauxTva): static
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    public function getUnite(): ?string
    {
        return $this->unite;
    }

    public function setUnite(?string $unite): static
    {
        $this->unite = $unite;

        return $this;
    }

    public function getProduit(): ?ProduitFournisseur
    {
        return $this->produit;
    }

    public function setProduit(?ProduitFournisseur $produit): static
    {
        $this->produit = $produit;

        return $this;
    }
}
