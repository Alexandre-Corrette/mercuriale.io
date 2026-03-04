<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\MotifAvoir;
use App\Enum\StatutAvoir;
use App\Repository\AvoirFournisseurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AvoirFournisseurRepository::class)]
#[ORM\Table(name: 'avoir_fournisseur')]
#[ORM\Index(columns: ['fournisseur_id'], name: 'idx_avoir_fournisseur')]
#[ORM\Index(columns: ['etablissement_id'], name: 'idx_avoir_etablissement')]
#[ORM\Index(columns: ['bon_livraison_id'], name: 'idx_avoir_bon_livraison')]
#[ORM\Index(columns: ['statut'], name: 'idx_avoir_statut')]
#[ORM\HasLifecycleCallbacks]
class AvoirFournisseur
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'La référence ne peut pas dépasser {{ limit }} caractères')]
    private ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class, inversedBy: 'avoirs')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Le fournisseur est obligatoire')]
    private ?Fournisseur $fournisseur = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'établissement est obligatoire')]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: BonLivraison::class, inversedBy: 'avoirs')]
    #[ORM\JoinColumn(nullable: true)]
    private ?BonLivraison $bonLivraison = null;

    #[ORM\Column(length: 20, enumType: StatutAvoir::class, options: ['default' => 'DEMANDE'])]
    private StatutAvoir $statut = StatutAvoir::DEMANDE;

    #[ORM\Column(length: 30, enumType: MotifAvoir::class)]
    #[Assert\NotNull(message: 'Le motif est obligatoire')]
    private ?MotifAvoir $motif = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le montant HT doit être positif ou nul')]
    private ?string $montantHt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le montant TVA doit être positif ou nul')]
    private ?string $montantTva = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le montant TTC doit être positif ou nul')]
    private ?string $montantTtc = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date de demande est obligatoire')]
    private ?\DateTimeImmutable $demandeLe = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recuLe = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $imputeLe = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $createdBy = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $validatedBy = null;

    /** @var Collection<int, LigneAvoir> */
    #[ORM\OneToMany(targetEntity: LigneAvoir::class, mappedBy: 'avoir', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lignes;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->lignes = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIdAsString(): string
    {
        return $this->id->toRfc4122();
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

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

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): static
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    public function getBonLivraison(): ?BonLivraison
    {
        return $this->bonLivraison;
    }

    public function setBonLivraison(?BonLivraison $bonLivraison): static
    {
        $this->bonLivraison = $bonLivraison;

        return $this;
    }

    public function getStatut(): StatutAvoir
    {
        return $this->statut;
    }

    public function setStatut(StatutAvoir $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getMotif(): ?MotifAvoir
    {
        return $this->motif;
    }

    public function setMotif(MotifAvoir $motif): static
    {
        $this->motif = $motif;

        return $this;
    }

    public function getMontantHt(): ?string
    {
        return $this->montantHt;
    }

    public function setMontantHt(?string $montantHt): static
    {
        $this->montantHt = $montantHt;

        return $this;
    }

    public function getMontantTva(): ?string
    {
        return $this->montantTva;
    }

    public function setMontantTva(?string $montantTva): static
    {
        $this->montantTva = $montantTva;

        return $this;
    }

    public function getMontantTtc(): ?string
    {
        return $this->montantTtc;
    }

    public function setMontantTtc(?string $montantTtc): static
    {
        $this->montantTtc = $montantTtc;

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

    public function getDemandeLe(): ?\DateTimeImmutable
    {
        return $this->demandeLe;
    }

    public function setDemandeLe(\DateTimeImmutable $demandeLe): static
    {
        $this->demandeLe = $demandeLe;

        return $this;
    }

    public function getRecuLe(): ?\DateTimeImmutable
    {
        return $this->recuLe;
    }

    public function setRecuLe(?\DateTimeImmutable $recuLe): static
    {
        $this->recuLe = $recuLe;

        return $this;
    }

    public function getImputeLe(): ?\DateTimeImmutable
    {
        return $this->imputeLe;
    }

    public function setImputeLe(?\DateTimeImmutable $imputeLe): static
    {
        $this->imputeLe = $imputeLe;

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
     * @return Collection<int, LigneAvoir>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneAvoir $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setAvoir($this);
        }

        return $this;
    }

    public function removeLigne(LigneAvoir $ligne): static
    {
        if ($this->lignes->removeElement($ligne)) {
            if ($ligne->getAvoir() === $this) {
                $ligne->setAvoir(null);
            }
        }

        return $this;
    }

    public function getNombreLignes(): int
    {
        return $this->lignes->count();
    }

    public function calculerMontantHt(): string
    {
        $total = '0';
        foreach ($this->lignes as $ligne) {
            $total = bcadd($total, $ligne->getMontantLigne() ?? '0', 2);
        }

        return $total;
    }

    public function __toString(): string
    {
        return $this->reference ?? 'Avoir #' . $this->id->toRfc4122();
    }
}
