<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\MotifSignalement;
use App\Enum\StatutSignalement;
use App\Repository\SignalementProduitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SignalementProduitRepository::class)]
#[ORM\Table(name: 'signalement_produit')]
#[ORM\Index(columns: ['etablissement_id'], name: 'idx_signalement_etablissement')]
#[ORM\Index(columns: ['statut'], name: 'idx_signalement_statut')]
#[ORM\Index(columns: ['motif'], name: 'idx_signalement_motif')]
#[ORM\HasLifecycleCallbacks]
class SignalementProduit
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20, unique: true, nullable: true)]
    private ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: LigneBonLivraison::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'La ligne de BL est obligatoire')]
    private ?LigneBonLivraison $ligneBonLivraison = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'établissement est obligatoire')]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 20, enumType: StatutSignalement::class, options: ['default' => 'SIGNALE'])]
    private StatutSignalement $statut = StatutSignalement::SIGNALE;

    #[ORM\Column(length: 30, enumType: MotifSignalement::class)]
    #[Assert\NotNull(message: 'Le motif est obligatoire')]
    private ?MotifSignalement $motif = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3)]
    #[Assert\NotNull(message: 'La quantité est obligatoire')]
    #[Assert\Positive(message: 'La quantité doit être positive')]
    private ?string $quantiteConcernee = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\ManyToOne(targetEntity: AvoirFournisseur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AvoirFournisseur $avoir = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reclameLe = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resoluLe = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $createdBy = null;

    /** @var Collection<int, PhotoSignalement> */
    #[ORM\OneToMany(targetEntity: PhotoSignalement::class, mappedBy: 'signalement', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $photos;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->photos = new ArrayCollection();
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

    public function getLigneBonLivraison(): ?LigneBonLivraison
    {
        return $this->ligneBonLivraison;
    }

    public function setLigneBonLivraison(?LigneBonLivraison $ligneBonLivraison): static
    {
        $this->ligneBonLivraison = $ligneBonLivraison;

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

    public function getStatut(): StatutSignalement
    {
        return $this->statut;
    }

    public function setStatut(StatutSignalement $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getMotif(): ?MotifSignalement
    {
        return $this->motif;
    }

    public function setMotif(MotifSignalement $motif): static
    {
        $this->motif = $motif;

        return $this;
    }

    public function getQuantiteConcernee(): ?string
    {
        return $this->quantiteConcernee;
    }

    public function setQuantiteConcernee(?string $quantiteConcernee): static
    {
        $this->quantiteConcernee = $quantiteConcernee;

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

    public function getAvoir(): ?AvoirFournisseur
    {
        return $this->avoir;
    }

    public function setAvoir(?AvoirFournisseur $avoir): static
    {
        $this->avoir = $avoir;

        return $this;
    }

    public function getReclameLe(): ?\DateTimeImmutable
    {
        return $this->reclameLe;
    }

    public function setReclameLe(?\DateTimeImmutable $reclameLe): static
    {
        $this->reclameLe = $reclameLe;

        return $this;
    }

    public function getResoluLe(): ?\DateTimeImmutable
    {
        return $this->resoluLe;
    }

    public function setResoluLe(?\DateTimeImmutable $resoluLe): static
    {
        $this->resoluLe = $resoluLe;

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

    /**
     * @return Collection<int, PhotoSignalement>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(PhotoSignalement $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setSignalement($this);
        }

        return $this;
    }

    public function removePhoto(PhotoSignalement $photo): static
    {
        if ($this->photos->removeElement($photo)) {
            if ($photo->getSignalement() === $this) {
                $photo->setSignalement(null);
            }
        }

        return $this;
    }

    public function getNombrePhotos(): int
    {
        return $this->photos->count();
    }

    public function __toString(): string
    {
        return $this->reference ?? 'Signalement #' . $this->id->toRfc4122();
    }
}
