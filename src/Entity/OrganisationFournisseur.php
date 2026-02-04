<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\OrganisationFournisseurRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrganisationFournisseurRepository::class)]
#[ORM\Table(name: 'organisation_fournisseur')]
#[ORM\UniqueConstraint(name: 'unique_org_fournisseur', columns: ['organisation_id', 'fournisseur_id'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['organisation', 'fournisseur'], message: 'Cette association organisation-fournisseur existe déjà')]
class OrganisationFournisseur
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class, inversedBy: 'organisationFournisseurs')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'organisation est obligatoire')]
    private ?Organisation $organisation = null;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class, inversedBy: 'organisationFournisseurs')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Le fournisseur est obligatoire')]
    private ?Fournisseur $fournisseur = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'Le code client ne peut pas dépasser {{ limit }} caractères')]
    private ?string $codeClient = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Le contact commercial ne peut pas dépasser {{ limit }} caractères')]
    private ?string $contactCommercial = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email(message: 'L\'adresse email de commande n\'est pas valide')]
    #[Assert\Length(max: 255, maxMessage: 'L\'email de commande ne peut pas dépasser {{ limit }} caractères')]
    private ?string $emailCommande = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getCodeClient(): ?string
    {
        return $this->codeClient;
    }

    public function setCodeClient(?string $codeClient): static
    {
        $this->codeClient = $codeClient;

        return $this;
    }

    public function getContactCommercial(): ?string
    {
        return $this->contactCommercial;
    }

    public function setContactCommercial(?string $contactCommercial): static
    {
        $this->contactCommercial = $contactCommercial;

        return $this;
    }

    public function getEmailCommande(): ?string
    {
        return $this->emailCommande;
    }

    public function setEmailCommande(?string $emailCommande): static
    {
        $this->emailCommande = $emailCommande;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function __toString(): string
    {
        $orgName = $this->organisation?->getNom() ?? '?';
        $fournisseurName = $this->fournisseur?->getNom() ?? '?';

        return sprintf('%s - %s', $orgName, $fournisseurName);
    }
}
