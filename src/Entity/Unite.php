<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TypeUnite;
use App\Repository\UniteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UniteRepository::class)]
#[ORM\Table(name: 'unite')]
#[UniqueEntity(fields: ['code'], message: 'Ce code d\'unité existe déjà')]
class Unite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le nom de l\'unité est obligatoire')]
    #[Assert\Length(max: 50, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères')]
    private ?string $nom = null;

    #[ORM\Column(length: 10, unique: true)]
    #[Assert\NotBlank(message: 'Le code de l\'unité est obligatoire')]
    #[Assert\Length(max: 10, maxMessage: 'Le code ne peut pas dépasser {{ limit }} caractères')]
    private ?string $code = null;

    #[ORM\Column(length: 20, enumType: TypeUnite::class)]
    #[Assert\NotNull(message: 'Le type d\'unité est obligatoire')]
    private ?TypeUnite $type = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $ordre = 0;

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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getType(): ?TypeUnite
    {
        return $this->type;
    }

    public function setType(TypeUnite $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function __toString(): string
    {
        return $this->code ?? '';
    }
}
