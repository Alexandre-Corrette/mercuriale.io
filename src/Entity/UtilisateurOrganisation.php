<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UtilisateurOrganisationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UtilisateurOrganisationRepository::class)]
#[ORM\Table(name: 'utilisateur_organisation')]
#[ORM\UniqueConstraint(name: 'unique_utilisateur_organisation', columns: ['utilisateur_id', 'organisation_id'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['utilisateur', 'organisation'], message: 'Cet utilisateur est déjà associé à cette organisation')]
class UtilisateurOrganisation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'utilisateurOrganisations')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'utilisateur est obligatoire')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class, inversedBy: 'utilisateurOrganisations')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'organisation est obligatoire')]
    private ?Organisation $organisation = null;

    #[ORM\Column(length: 20, options: ['default' => 'owner'])]
    #[Assert\NotBlank(message: 'Le rôle est obligatoire')]
    #[Assert\Length(max: 20, maxMessage: 'Le rôle ne peut pas dépasser {{ limit }} caractères')]
    private string $role = 'owner';

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

    public function getOrganisation(): ?Organisation
    {
        return $this->organisation;
    }

    public function setOrganisation(?Organisation $organisation): static
    {
        $this->organisation = $organisation;

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
