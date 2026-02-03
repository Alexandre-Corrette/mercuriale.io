<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\ProduitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProduitRepository::class)]
#[ORM\Table(name: 'produit')]
#[ORM\Index(columns: ['categorie_id'], name: 'idx_produit_categorie')]
#[ORM\HasLifecycleCallbacks]
class Produit
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom du produit est obligatoire')]
    #[Assert\Length(max: 255, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères')]
    private ?string $nom = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'Le code interne ne peut pas dépasser {{ limit }} caractères')]
    private ?string $codeInterne = null;

    #[ORM\ManyToOne(targetEntity: CategorieProduit::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CategorieProduit $categorie = null;

    #[ORM\ManyToOne(targetEntity: Unite::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'unité de base est obligatoire')]
    private ?Unite $uniteBase = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /** @var Collection<int, ProduitFournisseur> */
    #[ORM\OneToMany(targetEntity: ProduitFournisseur::class, mappedBy: 'produit')]
    private Collection $produitsFournisseur;

    public function __construct()
    {
        $this->produitsFournisseur = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getCodeInterne(): ?string
    {
        return $this->codeInterne;
    }

    public function setCodeInterne(?string $codeInterne): static
    {
        $this->codeInterne = $codeInterne;

        return $this;
    }

    public function getCategorie(): ?CategorieProduit
    {
        return $this->categorie;
    }

    public function setCategorie(?CategorieProduit $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getUniteBase(): ?Unite
    {
        return $this->uniteBase;
    }

    public function setUniteBase(?Unite $uniteBase): static
    {
        $this->uniteBase = $uniteBase;

        return $this;
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

    /**
     * @return Collection<int, ProduitFournisseur>
     */
    public function getProduitsFournisseur(): Collection
    {
        return $this->produitsFournisseur;
    }

    public function __toString(): string
    {
        return $this->nom ?? '';
    }
}
