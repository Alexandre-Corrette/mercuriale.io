<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\ContactFournisseurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContactFournisseurRepository::class)]
#[ORM\Table(name: 'contact_fournisseur')]
#[ORM\Index(columns: ['fournisseur_id'], name: 'idx_contact_fournisseur')]
#[ORM\HasLifecycleCallbacks]
class ContactFournisseur
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class, inversedBy: 'contacts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Le fournisseur est obligatoire')]
    private ?Fournisseur $fournisseur = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    #[Assert\Length(max: 100, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères')]
    private ?string $nom = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'Le prénom ne peut pas dépasser {{ limit }} caractères')]
    private ?string $prenom = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'Le rôle ne peut pas dépasser {{ limit }} caractères')]
    private ?string $role = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email(message: 'L\'adresse email n\'est pas valide')]
    #[Assert\Length(max: 255, maxMessage: 'L\'email ne peut pas dépasser {{ limit }} caractères')]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20, maxMessage: 'Le téléphone ne peut pas dépasser {{ limit }} caractères')]
    private ?string $telephone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $principal = false;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIdAsString(): string
    {
        return $this->id->toRfc4122();
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

    public function setPrenom(?string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getNomComplet(): string
    {
        return trim(($this->prenom ?? '') . ' ' . ($this->nom ?? ''));
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = $role;

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

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function isPrincipal(): bool
    {
        return $this->principal;
    }

    public function setPrincipal(bool $principal): static
    {
        $this->principal = $principal;

        return $this;
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }
}
