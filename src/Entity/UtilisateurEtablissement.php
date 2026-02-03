<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UtilisateurEtablissementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UtilisateurEtablissementRepository::class)]
#[ORM\Table(name: 'utilisateur_etablissement')]
#[ORM\UniqueConstraint(name: 'unique_utilisateur_etablissement', columns: ['utilisateur_id', 'etablissement_id'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['utilisateur', 'etablissement'], message: 'Cet utilisateur est déjà associé à cet établissement')]
class UtilisateurEtablissement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'utilisateurEtablissements')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'utilisateur est obligatoire')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class, inversedBy: 'utilisateurEtablissements')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'établissement est obligatoire')]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 50, options: ['default' => 'ROLE_VIEWER'])]
    #[Assert\NotBlank(message: 'Le rôle est obligatoire')]
    #[Assert\Length(max: 50, maxMessage: 'Le rôle ne peut pas dépasser {{ limit }} caractères')]
    private string $role = 'ROLE_VIEWER';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function initializeCreatedAt(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

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

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
