<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\FournisseurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FournisseurRepository::class)]
#[ORM\Table(name: 'fournisseur')]
#[ORM\HasLifecycleCallbacks]
class Fournisseur
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom du fournisseur est obligatoire')]
    #[Assert\Length(max: 255, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères')]
    private ?string $nom = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'Le code ne peut pas dépasser {{ limit }} caractères')]
    private ?string $code = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'L\'adresse ne peut pas dépasser {{ limit }} caractères')]
    private ?string $adresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Length(max: 10, maxMessage: 'Le code postal ne peut pas dépasser {{ limit }} caractères')]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'La ville ne peut pas dépasser {{ limit }} caractères')]
    private ?string $ville = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20, maxMessage: 'Le téléphone ne peut pas dépasser {{ limit }} caractères')]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email(message: 'L\'adresse email n\'est pas valide')]
    #[Assert\Length(max: 255, maxMessage: 'L\'email ne peut pas dépasser {{ limit }} caractères')]
    private ?string $email = null;

    #[ORM\Column(length: 14, nullable: true)]
    #[Assert\Length(exactly: 14, exactMessage: 'Le SIRET doit contenir exactement {{ limit }} caractères')]
    #[Assert\Regex(pattern: '/^\d{14}$/', message: 'Le SIRET doit contenir uniquement des chiffres')]
    private ?string $siret = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /** @var Collection<int, OrganisationFournisseur> */
    #[ORM\OneToMany(targetEntity: OrganisationFournisseur::class, mappedBy: 'fournisseur', orphanRemoval: true)]
    private Collection $organisationFournisseurs;

    /** @var Collection<int, ProduitFournisseur> */
    #[ORM\OneToMany(targetEntity: ProduitFournisseur::class, mappedBy: 'fournisseur')]
    private Collection $produitsFournisseur;

    /** @var Collection<int, BonLivraison> */
    #[ORM\OneToMany(targetEntity: BonLivraison::class, mappedBy: 'fournisseur')]
    private Collection $bonsLivraison;

    public function __construct()
    {
        $this->organisationFournisseurs = new ArrayCollection();
        $this->produitsFournisseur = new ArrayCollection();
        $this->bonsLivraison = new ArrayCollection();
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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

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

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

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
            $organisationFournisseur->setFournisseur($this);
        }

        return $this;
    }

    public function removeOrganisationFournisseur(OrganisationFournisseur $organisationFournisseur): static
    {
        if ($this->organisationFournisseurs->removeElement($organisationFournisseur)) {
            if ($organisationFournisseur->getFournisseur() === $this) {
                $organisationFournisseur->setFournisseur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ProduitFournisseur>
     */
    public function getProduitsFournisseur(): Collection
    {
        return $this->produitsFournisseur;
    }

    /**
     * @return Collection<int, BonLivraison>
     */
    public function getBonsLivraison(): Collection
    {
        return $this->bonsLivraison;
    }

    public function getAdresseComplete(): string
    {
        $parts = array_filter([$this->adresse, $this->codePostal, $this->ville]);

        return implode(', ', $parts);
    }

    public function __toString(): string
    {
        return $this->nom ?? '';
    }
}
