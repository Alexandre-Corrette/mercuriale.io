<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\StatutBonLivraison;
use App\Repository\BonLivraisonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BonLivraisonRepository::class)]
#[ORM\Table(name: 'bon_livraison')]
#[ORM\Index(columns: ['etablissement_id'], name: 'idx_bl_etablissement')]
#[ORM\Index(columns: ['fournisseur_id'], name: 'idx_bl_fournisseur')]
#[ORM\Index(columns: ['date_livraison'], name: 'idx_bl_date')]
#[ORM\Index(columns: ['statut'], name: 'idx_bl_statut')]
#[ORM\HasLifecycleCallbacks]
class BonLivraison
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class, inversedBy: 'bonsLivraison')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'établissement est obligatoire')]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class, inversedBy: 'bonsLivraison')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Le fournisseur est obligatoire')]
    private ?Fournisseur $fournisseur = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'Le numéro de BL ne peut pas dépasser {{ limit }} caractères')]
    private ?string $numeroBl = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'Le numéro de commande ne peut pas dépasser {{ limit }} caractères')]
    private ?string $numeroCommande = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date de livraison est obligatoire')]
    private ?\DateTimeImmutable $dateLivraison = null;

    #[ORM\Column(length: 20, enumType: StatutBonLivraison::class, options: ['default' => 'BROUILLON'])]
    private StatutBonLivraison $statut = StatutBonLivraison::BROUILLON;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'Le chemin d\'image ne peut pas dépasser {{ limit }} caractères')]
    private ?string $imagePath = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $donneesBrutes = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le total HT doit être positif ou nul')]
    private ?string $totalHt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $validatedBy = null;

    /** @var Collection<int, LigneBonLivraison> */
    #[ORM\OneToMany(targetEntity: LigneBonLivraison::class, mappedBy: 'bonLivraison', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $lignes;

    public function __construct()
    {
        $this->lignes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getNumeroBl(): ?string
    {
        return $this->numeroBl;
    }

    public function setNumeroBl(?string $numeroBl): static
    {
        $this->numeroBl = $numeroBl;

        return $this;
    }

    public function getNumeroCommande(): ?string
    {
        return $this->numeroCommande;
    }

    public function setNumeroCommande(?string $numeroCommande): static
    {
        $this->numeroCommande = $numeroCommande;

        return $this;
    }

    public function getDateLivraison(): ?\DateTimeImmutable
    {
        return $this->dateLivraison;
    }

    public function setDateLivraison(\DateTimeImmutable $dateLivraison): static
    {
        $this->dateLivraison = $dateLivraison;

        return $this;
    }

    public function getStatut(): StatutBonLivraison
    {
        return $this->statut;
    }

    public function setStatut(StatutBonLivraison $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): static
    {
        $this->imagePath = $imagePath;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDonneesBrutes(): ?array
    {
        return $this->donneesBrutes;
    }

    /**
     * @param array<string, mixed>|null $donneesBrutes
     */
    public function setDonneesBrutes(?array $donneesBrutes): static
    {
        $this->donneesBrutes = $donneesBrutes;

        return $this;
    }

    public function getTotalHt(): ?string
    {
        return $this->totalHt;
    }

    public function setTotalHt(?string $totalHt): static
    {
        $this->totalHt = $totalHt;

        return $this;
    }

    public function getTotalHtAsFloat(): ?float
    {
        return $this->totalHt !== null ? (float) $this->totalHt : null;
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

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function setValidatedAt(?\DateTimeImmutable $validatedAt): static
    {
        $this->validatedAt = $validatedAt;

        return $this;
    }

    public function getValidatedBy(): ?Utilisateur
    {
        return $this->validatedBy;
    }

    public function setValidatedBy(?Utilisateur $validatedBy): static
    {
        $this->validatedBy = $validatedBy;

        return $this;
    }

    /**
     * @return Collection<int, LigneBonLivraison>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneBonLivraison $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setBonLivraison($this);
        }

        return $this;
    }

    public function removeLigne(LigneBonLivraison $ligne): static
    {
        if ($this->lignes->removeElement($ligne)) {
            if ($ligne->getBonLivraison() === $this) {
                $ligne->setBonLivraison(null);
            }
        }

        return $this;
    }

    public function calculerTotalHt(): string
    {
        $total = '0';
        foreach ($this->lignes as $ligne) {
            $total = bcadd($total, $ligne->getTotalLigne() ?? '0', 4);
        }

        return $total;
    }

    public function getNombreLignes(): int
    {
        return $this->lignes->count();
    }

    public function __toString(): string
    {
        return $this->numeroBl ?? 'BL #' . $this->id;
    }
}
