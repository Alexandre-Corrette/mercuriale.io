<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\UtilisateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateur')]
#[ORM\Index(columns: ['organisation_id'], name: 'idx_utilisateur_organisation')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Cette adresse email est déjà utilisée')]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class, inversedBy: 'utilisateurs')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'organisation est obligatoire')]
    private ?Organisation $organisation = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'L\'adresse email est obligatoire')]
    #[Assert\Email(message: 'L\'adresse email n\'est pas valide')]
    #[Assert\Length(max: 180, maxMessage: 'L\'email ne peut pas dépasser {{ limit }} caractères')]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    #[Assert\Length(max: 100, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères')]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le prénom est obligatoire')]
    #[Assert\Length(max: 100, maxMessage: 'Le prénom ne peut pas dépasser {{ limit }} caractères')]
    private ?string $prenom = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /** @var Collection<int, UtilisateurEtablissement> */
    #[ORM\OneToMany(targetEntity: UtilisateurEtablissement::class, mappedBy: 'utilisateur', orphanRemoval: true)]
    private Collection $utilisateurEtablissements;

    public function __construct()
    {
        $this->utilisateurEtablissements = new ArrayCollection();
    }

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
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

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

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
     * @return Collection<int, UtilisateurEtablissement>
     */
    public function getUtilisateurEtablissements(): Collection
    {
        return $this->utilisateurEtablissements;
    }

    public function addUtilisateurEtablissement(UtilisateurEtablissement $utilisateurEtablissement): static
    {
        if (!$this->utilisateurEtablissements->contains($utilisateurEtablissement)) {
            $this->utilisateurEtablissements->add($utilisateurEtablissement);
            $utilisateurEtablissement->setUtilisateur($this);
        }

        return $this;
    }

    public function removeUtilisateurEtablissement(UtilisateurEtablissement $utilisateurEtablissement): static
    {
        if ($this->utilisateurEtablissements->removeElement($utilisateurEtablissement)) {
            if ($utilisateurEtablissement->getUtilisateur() === $this) {
                $utilisateurEtablissement->setUtilisateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Etablissement[]
     */
    public function getEtablissements(): array
    {
        return $this->utilisateurEtablissements
            ->map(fn (UtilisateurEtablissement $ue) => $ue->getEtablissement())
            ->toArray();
    }

    public function getFullName(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
