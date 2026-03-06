<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatutEmail;
use App\Repository\EmailContactFournisseurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EmailContactFournisseurRepository::class)]
#[ORM\Table(name: 'email_contact_fournisseur')]
#[ORM\Index(columns: ['contact_id'], name: 'idx_email_contact')]
#[ORM\Index(columns: ['sent_by_id'], name: 'idx_email_sent_by')]
#[ORM\Index(columns: ['sent_at'], name: 'idx_email_sent_at')]
class EmailContactFournisseur
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ContactFournisseur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Le contact est obligatoire')]
    private ?ContactFournisseur $contact = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'expéditeur est obligatoire')]
    private ?Utilisateur $sentBy = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'L\'objet est obligatoire')]
    #[Assert\Length(max: 255, maxMessage: 'L\'objet ne peut pas dépasser {{ limit }} caractères')]
    private ?string $subject = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Le corps du message est obligatoire')]
    private ?string $body = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(type: 'string', enumType: StatutEmail::class)]
    private StatutEmail $status = StatutEmail::SENT;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getContact(): ?ContactFournisseur
    {
        return $this->contact;
    }

    public function setContact(?ContactFournisseur $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getSentBy(): ?Utilisateur
    {
        return $this->sentBy;
    }

    public function setSentBy(?Utilisateur $sentBy): static
    {
        $this->sentBy = $sentBy;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getStatus(): StatutEmail
    {
        return $this->status;
    }

    public function setStatus(StatutEmail $status): static
    {
        $this->status = $status;

        return $this;
    }
}