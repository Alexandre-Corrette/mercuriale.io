<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatutControle;
use App\Repository\LigneBonLivraisonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LigneBonLivraisonRepository::class)]
#[ORM\Table(name: 'ligne_bon_livraison')]
#[ORM\Index(columns: ['bon_livraison_id'], name: 'idx_ligne_bl')]
#[ORM\Index(columns: ['produit_fournisseur_id'], name: 'idx_ligne_produit_fournisseur')]
class LigneBonLivraison
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BonLivraison::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Le bon de livraison est obligatoire')]
    private ?BonLivraison $bonLivraison = null;

    #[ORM\ManyToOne(targetEntity: ProduitFournisseur::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ProduitFournisseur $produitFournisseur = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'Le code produit ne peut pas dépasser {{ limit }} caractères')]
    private ?string $codeProduitBl = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'La désignation est obligatoire')]
    #[Assert\Length(max: 255, maxMessage: 'La désignation ne peut pas dépasser {{ limit }} caractères')]
    private ?string $designationBl = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3, nullable: true)]
    #[Assert\PositiveOrZero(message: 'La quantité commandée doit être positive ou nulle')]
    private ?string $quantiteCommandee = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3)]
    #[Assert\NotNull(message: 'La quantité livrée est obligatoire')]
    #[Assert\PositiveOrZero(message: 'La quantité livrée doit être positive ou nulle')]
    private ?string $quantiteLivree = null;

    #[ORM\ManyToOne(targetEntity: Unite::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'L\'unité est obligatoire')]
    private ?Unite $unite = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    #[Assert\NotNull(message: 'Le prix unitaire est obligatoire')]
    #[Assert\PositiveOrZero(message: 'Le prix unitaire doit être positif ou nul')]
    private ?string $prixUnitaire = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4)]
    #[Assert\NotNull(message: 'Le total ligne est obligatoire')]
    private ?string $totalLigne = null;

    #[ORM\Column(length: 20, enumType: StatutControle::class, options: ['default' => 'NON_CONTROLE'])]
    private StatutControle $statutControle = StatutControle::NON_CONTROLE;

    #[ORM\Column(options: ['default' => false])]
    private bool $valide = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $ordre = 0;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $uniteLivraison = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $quantiteFacturee = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $uniteFacturation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $majorationDecote = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codeTva = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $origine = null;

    #[ORM\Column(nullable: true)]
    private ?int $numeroLigneBl = null;

    /** @var Collection<int, AlerteControle> */
    #[ORM\OneToMany(targetEntity: AlerteControle::class, mappedBy: 'ligneBl', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $alertes;

    public function __construct()
    {
        $this->alertes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBonLivraison(): ?BonLivraison
    {
        return $this->bonLivraison;
    }

    public function setBonLivraison(?BonLivraison $bonLivraison): static
    {
        $this->bonLivraison = $bonLivraison;

        return $this;
    }

    public function getProduitFournisseur(): ?ProduitFournisseur
    {
        return $this->produitFournisseur;
    }

    public function setProduitFournisseur(?ProduitFournisseur $produitFournisseur): static
    {
        $this->produitFournisseur = $produitFournisseur;

        return $this;
    }

    public function getCodeProduitBl(): ?string
    {
        return $this->codeProduitBl;
    }

    public function setCodeProduitBl(?string $codeProduitBl): static
    {
        $this->codeProduitBl = $codeProduitBl;

        return $this;
    }

    public function getDesignationBl(): ?string
    {
        return $this->designationBl;
    }

    public function setDesignationBl(string $designationBl): static
    {
        $this->designationBl = $designationBl;

        return $this;
    }

    public function getQuantiteCommandee(): ?string
    {
        return $this->quantiteCommandee;
    }

    public function setQuantiteCommandee(?string $quantiteCommandee): static
    {
        $this->quantiteCommandee = $quantiteCommandee;

        return $this;
    }

    public function getQuantiteLivree(): ?string
    {
        return $this->quantiteLivree;
    }

    public function setQuantiteLivree(?string $quantiteLivree): static
    {
        $this->quantiteLivree = $quantiteLivree;

        return $this;
    }

    public function getUnite(): ?Unite
    {
        return $this->unite;
    }

    public function setUnite(?Unite $unite): static
    {
        $this->unite = $unite;

        return $this;
    }

    public function getPrixUnitaire(): ?string
    {
        return $this->prixUnitaire;
    }

    public function setPrixUnitaire(string $prixUnitaire): static
    {
        $this->prixUnitaire = $prixUnitaire;

        return $this;
    }

    public function getPrixUnitaireAsFloat(): float
    {
        return (float) $this->prixUnitaire;
    }

    public function getTotalLigne(): ?string
    {
        return $this->totalLigne;
    }

    public function setTotalLigne(string $totalLigne): static
    {
        $this->totalLigne = $totalLigne;

        return $this;
    }

    public function getTotalLigneAsFloat(): float
    {
        return (float) $this->totalLigne;
    }

    public function getStatutControle(): StatutControle
    {
        return $this->statutControle;
    }

    public function setStatutControle(StatutControle $statutControle): static
    {
        $this->statutControle = $statutControle;

        return $this;
    }

    public function isValide(): bool
    {
        return $this->valide;
    }

    public function setValide(bool $valide): static
    {
        $this->valide = $valide;

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

    /**
     * @return Collection<int, AlerteControle>
     */
    public function getAlertes(): Collection
    {
        return $this->alertes;
    }

    public function addAlerte(AlerteControle $alerte): static
    {
        if (!$this->alertes->contains($alerte)) {
            $this->alertes->add($alerte);
            $alerte->setLigneBl($this);
        }

        return $this;
    }

    public function removeAlerte(AlerteControle $alerte): static
    {
        if ($this->alertes->removeElement($alerte)) {
            if ($alerte->getLigneBl() === $this) {
                $alerte->setLigneBl(null);
            }
        }

        return $this;
    }

    public function getUniteLivraison(): ?string
    {
        return $this->uniteLivraison;
    }

    public function setUniteLivraison(?string $uniteLivraison): static
    {
        $this->uniteLivraison = $uniteLivraison;

        return $this;
    }

    public function getQuantiteFacturee(): ?string
    {
        return $this->quantiteFacturee;
    }

    public function setQuantiteFacturee(?string $quantiteFacturee): static
    {
        $this->quantiteFacturee = $quantiteFacturee;

        return $this;
    }

    public function getUniteFacturation(): ?string
    {
        return $this->uniteFacturation;
    }

    public function setUniteFacturation(?string $uniteFacturation): static
    {
        $this->uniteFacturation = $uniteFacturation;

        return $this;
    }

    public function getMajorationDecote(): ?string
    {
        return $this->majorationDecote;
    }

    public function setMajorationDecote(?string $majorationDecote): static
    {
        $this->majorationDecote = $majorationDecote;

        return $this;
    }

    public function getCodeTva(): ?string
    {
        return $this->codeTva;
    }

    public function setCodeTva(?string $codeTva): static
    {
        $this->codeTva = $codeTva;

        return $this;
    }

    public function getOrigine(): ?string
    {
        return $this->origine;
    }

    public function setOrigine(?string $origine): static
    {
        $this->origine = $origine;

        return $this;
    }

    public function getNumeroLigneBl(): ?int
    {
        return $this->numeroLigneBl;
    }

    public function setNumeroLigneBl(?int $numeroLigneBl): static
    {
        $this->numeroLigneBl = $numeroLigneBl;

        return $this;
    }

    public function calculerTotalLigne(): string
    {
        if ($this->quantiteLivree === null || $this->prixUnitaire === null) {
            return '0';
        }

        return bcmul($this->quantiteLivree, $this->prixUnitaire, 4);
    }

    public function hasAlertes(): bool
    {
        return !$this->alertes->isEmpty();
    }
}
