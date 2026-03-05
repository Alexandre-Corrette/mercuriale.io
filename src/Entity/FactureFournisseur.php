<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\SourceFacture;
use App\Enum\StatutFacture;
use App\Repository\FactureFournisseurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FactureFournisseurRepository::class)]
#[ORM\Table(name: 'facture_fournisseur')]
#[ORM\UniqueConstraint(name: 'uniq_facture_external_id', columns: ['external_id'])]
#[ORM\Index(columns: ['fournisseur_id'], name: 'idx_facture_fournisseur')]
#[ORM\Index(columns: ['etablissement_id'], name: 'idx_facture_etablissement')]
#[ORM\Index(columns: ['statut'], name: 'idx_facture_statut')]
#[ORM\Index(columns: ['source'], name: 'idx_facture_source')]
#[ORM\HasLifecycleCallbacks]
class FactureFournisseur
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** B2Brouter invoice ID — unique, used for deduplication (null for OCR uploads) */
    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 20, enumType: SourceFacture::class, options: ['default' => 'B2BROUTER'])]
    private SourceFacture $source = SourceFacture::B2BROUTER;

    /** Raw JSON response from Claude Vision OCR */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $ocrRawData = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ocrProcessedAt = null;

    /** Original filename before UUID rename (display only) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fichierOriginalNom = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $numeroFacture = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateEmission = null;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Fournisseur $fournisseur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fournisseurNom = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $fournisseurTva = null;

    #[ORM\Column(length: 9, nullable: true)]
    private ?string $fournisseurSiren = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'établissement est obligatoire')]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $acheteurNom = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $acheteurTva = null;

    #[ORM\Column(length: 20, enumType: StatutFacture::class, options: ['default' => 'RECUE'])]
    private StatutFacture $statut = StatutFacture::RECUE;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $montantHt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $montantTva = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $montantTtc = null;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $devise = 'EUR';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motifRefus = null;

    /** Path to the archived original document (PDF) */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $documentOriginalPath = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $accepteeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $payeeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rapprocheLe = null;

    /** Écart montant HT entre facture et BL (facture - BL) */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $ecartMontantHt = null;

    /** Confidence score 0-100 for the BL match */
    #[ORM\Column(nullable: true)]
    private ?int $scoreRapprochement = null;

    #[ORM\ManyToOne(targetEntity: BonLivraison::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?BonLivraison $bonLivraison = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $createdBy = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $validatedBy = null;

    /** @var Collection<int, LigneFactureFournisseur> */
    #[ORM\OneToMany(targetEntity: LigneFactureFournisseur::class, mappedBy: 'facture', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getSource(): SourceFacture
    {
        return $this->source;
    }

    public function setSource(SourceFacture $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getOcrRawData(): ?array
    {
        return $this->ocrRawData;
    }

    public function setOcrRawData(?array $ocrRawData): static
    {
        $this->ocrRawData = $ocrRawData;

        return $this;
    }

    public function getOcrProcessedAt(): ?\DateTimeImmutable
    {
        return $this->ocrProcessedAt;
    }

    public function setOcrProcessedAt(?\DateTimeImmutable $ocrProcessedAt): static
    {
        $this->ocrProcessedAt = $ocrProcessedAt;

        return $this;
    }

    public function getFichierOriginalNom(): ?string
    {
        return $this->fichierOriginalNom;
    }

    public function setFichierOriginalNom(?string $fichierOriginalNom): static
    {
        $this->fichierOriginalNom = $fichierOriginalNom;

        return $this;
    }

    public function getNumeroFacture(): ?string
    {
        return $this->numeroFacture;
    }

    public function setNumeroFacture(?string $numeroFacture): static
    {
        $this->numeroFacture = $numeroFacture;

        return $this;
    }

    public function getDateEmission(): ?\DateTimeImmutable
    {
        return $this->dateEmission;
    }

    public function setDateEmission(?\DateTimeImmutable $dateEmission): static
    {
        $this->dateEmission = $dateEmission;

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

    public function getFournisseurNom(): ?string
    {
        return $this->fournisseurNom;
    }

    public function setFournisseurNom(?string $fournisseurNom): static
    {
        $this->fournisseurNom = $fournisseurNom;

        return $this;
    }

    public function getFournisseurTva(): ?string
    {
        return $this->fournisseurTva;
    }

    public function setFournisseurTva(?string $fournisseurTva): static
    {
        $this->fournisseurTva = $fournisseurTva;

        return $this;
    }

    public function getFournisseurSiren(): ?string
    {
        return $this->fournisseurSiren;
    }

    public function setFournisseurSiren(?string $fournisseurSiren): static
    {
        $this->fournisseurSiren = $fournisseurSiren;

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

    public function getAcheteurNom(): ?string
    {
        return $this->acheteurNom;
    }

    public function setAcheteurNom(?string $acheteurNom): static
    {
        $this->acheteurNom = $acheteurNom;

        return $this;
    }

    public function getAcheteurTva(): ?string
    {
        return $this->acheteurTva;
    }

    public function setAcheteurTva(?string $acheteurTva): static
    {
        $this->acheteurTva = $acheteurTva;

        return $this;
    }

    public function getStatut(): StatutFacture
    {
        return $this->statut;
    }

    public function setStatut(StatutFacture $statut): static
    {
        $this->statut = $statut;

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

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): static
    {
        $this->devise = $devise;

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

    public function getMotifRefus(): ?string
    {
        return $this->motifRefus;
    }

    public function setMotifRefus(?string $motifRefus): static
    {
        $this->motifRefus = $motifRefus;

        return $this;
    }

    public function getDocumentOriginalPath(): ?string
    {
        return $this->documentOriginalPath;
    }

    public function setDocumentOriginalPath(?string $documentOriginalPath): static
    {
        $this->documentOriginalPath = $documentOriginalPath;

        return $this;
    }

    public function getAccepteeLe(): ?\DateTimeImmutable
    {
        return $this->accepteeLe;
    }

    public function setAccepteeLe(?\DateTimeImmutable $accepteeLe): static
    {
        $this->accepteeLe = $accepteeLe;

        return $this;
    }

    public function getPayeeLe(): ?\DateTimeImmutable
    {
        return $this->payeeLe;
    }

    public function setPayeeLe(?\DateTimeImmutable $payeeLe): static
    {
        $this->payeeLe = $payeeLe;

        return $this;
    }

    public function getRapprocheLe(): ?\DateTimeImmutable
    {
        return $this->rapprocheLe;
    }

    public function setRapprocheLe(?\DateTimeImmutable $rapprocheLe): static
    {
        $this->rapprocheLe = $rapprocheLe;

        return $this;
    }

    public function getEcartMontantHt(): ?string
    {
        return $this->ecartMontantHt;
    }

    public function setEcartMontantHt(?string $ecartMontantHt): static
    {
        $this->ecartMontantHt = $ecartMontantHt;

        return $this;
    }

    public function getScoreRapprochement(): ?int
    {
        return $this->scoreRapprochement;
    }

    public function setScoreRapprochement(?int $scoreRapprochement): static
    {
        $this->scoreRapprochement = $scoreRapprochement;

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
     * @return Collection<int, LigneFactureFournisseur>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneFactureFournisseur $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setFacture($this);
        }

        return $this;
    }

    public function removeLigne(LigneFactureFournisseur $ligne): static
    {
        if ($this->lignes->removeElement($ligne)) {
            if ($ligne->getFacture() === $this) {
                $ligne->setFacture(null);
            }
        }

        return $this;
    }
}
