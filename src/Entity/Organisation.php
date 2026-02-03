<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\OrganisationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrganisationRepository::class)]
#[ORM\Table(name: 'organisation')]
#[ORM\HasLifecycleCallbacks]
class Organisation
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom de l\'organisation est obligatoire')]
    #[Assert\Length(max: 255, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères')]
    private ?string $nom = null;

    #[ORM\Column(length: 14, nullable: true)]
    #[Assert\Length(exactly: 14, exactMessage: 'Le SIRET doit contenir exactement {{ limit }} caractères')]
    #[Assert\Regex(pattern: '/^\d{14}$/', message: 'Le SIRET doit contenir uniquement des chiffres')]
    private ?string $siret = null;

    /** @var Collection<int, Etablissement> */
    #[ORM\OneToMany(targetEntity: Etablissement::class, mappedBy: 'organisation', orphanRemoval: true)]
    private Collection $etablissements;

    /** @var Collection<int, Utilisateur> */
    #[ORM\OneToMany(targetEntity: Utilisateur::class, mappedBy: 'organisation', orphanRemoval: true)]
    private Collection $utilisateurs;

    /** @var Collection<int, Fournisseur> */
    #[ORM\OneToMany(targetEntity: Fournisseur::class, mappedBy: 'organisation', orphanRemoval: true)]
    private Collection $fournisseurs;

    public function __construct()
    {
        $this->etablissements = new ArrayCollection();
        $this->utilisateurs = new ArrayCollection();
        $this->fournisseurs = new ArrayCollection();
    }

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

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $this->siret = $siret;

        return $this;
    }

    /**
     * @return Collection<int, Etablissement>
     */
    public function getEtablissements(): Collection
    {
        return $this->etablissements;
    }

    public function addEtablissement(Etablissement $etablissement): static
    {
        if (!$this->etablissements->contains($etablissement)) {
            $this->etablissements->add($etablissement);
            $etablissement->setOrganisation($this);
        }

        return $this;
    }

    public function removeEtablissement(Etablissement $etablissement): static
    {
        if ($this->etablissements->removeElement($etablissement)) {
            if ($etablissement->getOrganisation() === $this) {
                $etablissement->setOrganisation(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Utilisateur>
     */
    public function getUtilisateurs(): Collection
    {
        return $this->utilisateurs;
    }

    public function addUtilisateur(Utilisateur $utilisateur): static
    {
        if (!$this->utilisateurs->contains($utilisateur)) {
            $this->utilisateurs->add($utilisateur);
            $utilisateur->setOrganisation($this);
        }

        return $this;
    }

    public function removeUtilisateur(Utilisateur $utilisateur): static
    {
        if ($this->utilisateurs->removeElement($utilisateur)) {
            if ($utilisateur->getOrganisation() === $this) {
                $utilisateur->setOrganisation(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Fournisseur>
     */
    public function getFournisseurs(): Collection
    {
        return $this->fournisseurs;
    }

    public function addFournisseur(Fournisseur $fournisseur): static
    {
        if (!$this->fournisseurs->contains($fournisseur)) {
            $this->fournisseurs->add($fournisseur);
            $fournisseur->setOrganisation($this);
        }

        return $this;
    }

    public function removeFournisseur(Fournisseur $fournisseur): static
    {
        if ($this->fournisseurs->removeElement($fournisseur)) {
            if ($fournisseur->getOrganisation() === $this) {
                $fournisseur->setOrganisation(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom ?? '';
    }
}
