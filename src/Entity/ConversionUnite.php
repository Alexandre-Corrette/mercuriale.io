<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversionUniteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ConversionUniteRepository::class)]
#[ORM\Table(name: 'conversion_unite')]
#[ORM\UniqueConstraint(name: 'unique_conversion', columns: ['unite_source_id', 'unite_cible_id'])]
#[UniqueEntity(fields: ['uniteSource', 'uniteCible'], message: 'Cette conversion existe déjà')]
class ConversionUnite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Unite::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'unité source est obligatoire')]
    private ?Unite $uniteSource = null;

    #[ORM\ManyToOne(targetEntity: Unite::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'unité cible est obligatoire')]
    private ?Unite $uniteCible = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 6)]
    #[Assert\NotNull(message: 'Le facteur de conversion est obligatoire')]
    #[Assert\Positive(message: 'Le facteur doit être positif')]
    private ?string $facteur = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUniteSource(): ?Unite
    {
        return $this->uniteSource;
    }

    public function setUniteSource(?Unite $uniteSource): static
    {
        $this->uniteSource = $uniteSource;

        return $this;
    }

    public function getUniteCible(): ?Unite
    {
        return $this->uniteCible;
    }

    public function setUniteCible(?Unite $uniteCible): static
    {
        $this->uniteCible = $uniteCible;

        return $this;
    }

    public function getFacteur(): ?string
    {
        return $this->facteur;
    }

    public function setFacteur(string $facteur): static
    {
        $this->facteur = $facteur;

        return $this;
    }

    public function getFacteurAsFloat(): float
    {
        return (float) $this->facteur;
    }
}
