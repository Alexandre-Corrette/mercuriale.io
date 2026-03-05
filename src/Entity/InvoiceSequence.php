<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\InvoiceSequenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InvoiceSequenceRepository::class)]
#[ORM\Table(name: 'invoice_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_sequence_org_year', columns: ['organisation_id', 'year'])]
#[ORM\HasLifecycleCallbacks]
class InvoiceSequence
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private Organisation $organisation;

    #[ORM\Column]
    private int $year;

    #[ORM\Column]
    private int $lastNumber = 0;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    #[Assert\Regex(pattern: '/^[A-Z0-9\-]{1,20}$/', message: 'Le préfixe ne peut contenir que des lettres majuscules, chiffres et tirets')]
    private string $prefix = 'FAC';

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Length(max: 10)]
    private ?string $suffix = null;

    #[ORM\Column]
    #[Assert\Range(min: 4, max: 6)]
    private int $paddingLength = 5;

    public function __construct(Organisation $organisation, int $year)
    {
        $this->id = Uuid::v4();
        $this->organisation = $organisation;
        $this->year = $year;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganisation(): Organisation
    {
        return $this->organisation;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getLastNumber(): int
    {
        return $this->lastNumber;
    }

    public function incrementAndGet(): int
    {
        return ++$this->lastNumber;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function getSuffix(): ?string
    {
        return $this->suffix;
    }

    public function setSuffix(?string $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function getPaddingLength(): int
    {
        return $this->paddingLength;
    }

    public function setPaddingLength(int $paddingLength): static
    {
        $this->paddingLength = $paddingLength;

        return $this;
    }

    public function formatNumber(int $number): string
    {
        $formatted = $this->prefix . '-' . $this->year . '-' . str_pad((string) $number, $this->paddingLength, '0', STR_PAD_LEFT);

        if ($this->suffix !== null && $this->suffix !== '') {
            $formatted .= $this->suffix;
        }

        return $formatted;
    }

    public function resetSequence(): static
    {
        $this->lastNumber = 0;

        return $this;
    }
}
