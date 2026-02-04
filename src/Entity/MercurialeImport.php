<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\StatutImport;
use App\Repository\MercurialeImportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MercurialeImportRepository::class)]
#[ORM\Table(name: 'mercuriale_import')]
#[ORM\Index(columns: ['fournisseur_id'], name: 'idx_mercuriale_import_fournisseur')]
#[ORM\Index(columns: ['etablissement_id'], name: 'idx_mercuriale_import_etablissement')]
#[ORM\Index(columns: ['status'], name: 'idx_mercuriale_import_status')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_mercuriale_import_expires')]
#[ORM\HasLifecycleCallbacks]
class MercurialeImport
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Le fournisseur est obligatoire')]
    private ?Fournisseur $fournisseur = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'utilisateur est obligatoire')]
    private ?Utilisateur $createdBy = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom du fichier est obligatoire')]
    private ?string $originalFilename = null;

    #[ORM\Column(type: Types::JSON)]
    private array $parsedData = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $columnMapping = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $previewResult = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $importResult = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: StatutImport::class)]
    private StatutImport $status = StatutImport::PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::INTEGER)]
    private int $totalRows = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $detectedHeaders = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->expiresAt = new \DateTimeImmutable('+1 hour');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIdAsString(): string
    {
        return $this->id->toRfc4122();
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

    public function getCreatedBy(): ?Utilisateur
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?Utilisateur $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getParsedData(): array
    {
        return $this->parsedData;
    }

    public function setParsedData(array $parsedData): static
    {
        $this->parsedData = $parsedData;

        return $this;
    }

    public function getColumnMapping(): ?array
    {
        return $this->columnMapping;
    }

    public function setColumnMapping(?array $columnMapping): static
    {
        $this->columnMapping = $columnMapping;

        return $this;
    }

    public function getPreviewResult(): ?array
    {
        return $this->previewResult;
    }

    public function setPreviewResult(?array $previewResult): static
    {
        $this->previewResult = $previewResult;

        return $this;
    }

    public function getImportResult(): ?array
    {
        return $this->importResult;
    }

    public function setImportResult(?array $importResult): static
    {
        $this->importResult = $importResult;

        return $this;
    }

    public function getStatus(): StatutImport
    {
        return $this->status;
    }

    public function setStatus(StatutImport $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function setTotalRows(int $totalRows): static
    {
        $this->totalRows = $totalRows;

        return $this;
    }

    public function getDetectedHeaders(): ?array
    {
        return $this->detectedHeaders;
    }

    public function setDetectedHeaders(?array $detectedHeaders): static
    {
        $this->detectedHeaders = $detectedHeaders;

        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function canBeProcessed(): bool
    {
        return !$this->isExpired() && \in_array($this->status, [
            StatutImport::PENDING,
            StatutImport::MAPPING,
            StatutImport::PREVIEWED,
        ], true);
    }

    public function extendExpiration(): static
    {
        $this->expiresAt = new \DateTimeImmutable('+1 hour');

        return $this;
    }
}
