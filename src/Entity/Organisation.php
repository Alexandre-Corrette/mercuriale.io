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

    #[ORM\Column(length: 9, nullable: true, unique: true)]
    #[Assert\Regex(pattern: '/^\d{9}$/', message: 'Le SIREN doit contenir exactement 9 chiffres')]
    private ?string $siren = null;

    #[ORM\Column(length: 14, nullable: true)]
    #[Assert\Length(exactly: 14, exactMessage: 'Le SIRET doit contenir exactement {{ limit }} caractères')]
    #[Assert\Regex(pattern: '/^\d{14}$/', message: 'Le SIRET doit contenir uniquement des chiffres')]
    private ?string $siret = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeAccountId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeVerificationSessionId = null;

    /** @var Collection<int, Etablissement> */
    #[ORM\OneToMany(targetEntity: Etablissement::class, mappedBy: 'organisation', orphanRemoval: true)]
    private Collection $etablissements;

    /** @var Collection<int, Utilisateur> */
    #[ORM\OneToMany(targetEntity: Utilisateur::class, mappedBy: 'organisation', orphanRemoval: true)]
    private Collection $utilisateurs;

    /** @var Collection<int, OrganisationFournisseur> */
    #[ORM\OneToMany(targetEntity: OrganisationFournisseur::class, mappedBy: 'organisation', orphanRemoval: true)]
    private Collection $organisationFournisseurs;

    #[ORM\OneToOne(targetEntity: Abonnement::class, mappedBy: 'organisation')]
    private ?Abonnement $abonnement = null;

    /** @var Collection<int, UtilisateurOrganisation> */
    #[ORM\OneToMany(targetEntity: UtilisateurOrganisation::class, mappedBy: 'organisation', orphanRemoval: true)]
    private Collection $utilisateurOrganisations;

    public function __construct()
    {
        $this->etablissements = new ArrayCollection();
        $this->utilisateurs = new ArrayCollection();
        $this->organisationFournisseurs = new ArrayCollection();
        $this->utilisateurOrganisations = new ArrayCollection();
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

    public function getSiren(): ?string
    {
        return $this->siren;
    }

    public function setSiren(?string $siren): static
    {
        $this->siren = $siren;

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
     * @return Collection<int, OrganisationFournisseur>
     */
    public function getOrganisationFournisseurs(): Collection
    {
        return $this->organisationFournisseurs;
    }

    public function addOrganisationFournisseur(OrganisationFournisseur $organisationFournisseur): static
    {
        if (!$this->organisationFournisseurs->contains($organisationFournisseur)) {
            $this->organisationFournisseurs->add($organisationFournisseur);
            $organisationFournisseur->setOrganisation($this);
        }

        return $this;
    }

    public function removeOrganisationFournisseur(OrganisationFournisseur $organisationFournisseur): static
    {
        if ($this->organisationFournisseurs->removeElement($organisationFournisseur)) {
            if ($organisationFournisseur->getOrganisation() === $this) {
                $organisationFournisseur->setOrganisation(null);
            }
        }

        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): static
    {
        $this->trialEndsAt = $trialEndsAt;

        return $this;
    }

    public function getStripeAccountId(): ?string
    {
        return $this->stripeAccountId;
    }

    public function setStripeAccountId(?string $stripeAccountId): static
    {
        $this->stripeAccountId = $stripeAccountId;

        return $this;
    }

    public function getStripeVerificationSessionId(): ?string
    {
        return $this->stripeVerificationSessionId;
    }

    public function setStripeVerificationSessionId(?string $stripeVerificationSessionId): static
    {
        $this->stripeVerificationSessionId = $stripeVerificationSessionId;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }

    public function isTrialActive(): bool
    {
        if ($this->trialEndsAt === null) {
            return false;
        }

        return new \DateTimeImmutable() < $this->trialEndsAt;
    }

    public function isTrialExpired(): bool
    {
        if ($this->trialEndsAt === null) {
            return false;
        }

        return new \DateTimeImmutable() >= $this->trialEndsAt;
    }

    public function getTrialDaysRemaining(): int
    {
        if ($this->trialEndsAt === null) {
            return 0;
        }

        $now = new \DateTimeImmutable();
        if ($now >= $this->trialEndsAt) {
            return 0;
        }

        return (int) $now->diff($this->trialEndsAt)->days;
    }

    /**
     * @return Collection<int, UtilisateurOrganisation>
     */
    public function getUtilisateurOrganisations(): Collection
    {
        return $this->utilisateurOrganisations;
    }

    public function addUtilisateurOrganisation(UtilisateurOrganisation $utilisateurOrganisation): static
    {
        if (!$this->utilisateurOrganisations->contains($utilisateurOrganisation)) {
            $this->utilisateurOrganisations->add($utilisateurOrganisation);
            $utilisateurOrganisation->setOrganisation($this);
        }

        return $this;
    }

    public function removeUtilisateurOrganisation(UtilisateurOrganisation $utilisateurOrganisation): static
    {
        if ($this->utilisateurOrganisations->removeElement($utilisateurOrganisation)) {
            if ($utilisateurOrganisation->getOrganisation() === $this) {
                $utilisateurOrganisation->setOrganisation(null);
            }
        }

        return $this;
    }

    public function getAbonnement(): ?Abonnement
    {
        return $this->abonnement;
    }

    public function setAbonnement(?Abonnement $abonnement): static
    {
        if ($abonnement !== null && $abonnement->getOrganisation() !== $this) {
            $abonnement->setOrganisation($this);
        }

        $this->abonnement = $abonnement;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom ?? '';
    }
}
