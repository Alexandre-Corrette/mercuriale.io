<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Fournisseur;
use App\Entity\OrganisationFournisseur;
use App\Entity\Utilisateur;
use App\Repository\OrganisationFournisseurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
class FournisseurCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly OrganisationFournisseurRepository $organisationFournisseurRepository,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Fournisseur::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Fournisseur')
            ->setEntityLabelInPlural('Fournisseurs')
            ->setSearchFields(['nom', 'code', 'ville', 'siret'])
            ->setDefaultSort(['nom' => 'ASC'])
            ->setHelp('index', 'Gérez vos fournisseurs. Utilisez le champ "Restaurants" pour associer un fournisseur à vos établissements.');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        $organisation = $user?->getOrganisation();

        if ($organisation === null) {
            $qb->andWhere('1 = 0');

            return $qb;
        }

        // Show fournisseurs linked to user's org via OrganisationFournisseur OR via Etablissement
        $qb->leftJoin('entity.organisationFournisseurs', 'orgf', 'WITH', 'orgf.organisation = :organisation AND orgf.actif = true')
            ->leftJoin('entity.etablissements', 'etab', 'WITH', 'etab.organisation = :organisation AND etab.actif = true')
            ->andWhere('orgf.id IS NOT NULL OR etab.id IS NOT NULL')
            ->setParameter('organisation', $organisation);

        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        $organisation = $user?->getOrganisation();

        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'Code');
        yield TextField::new('nom', 'Nom')
            ->setRequired(true);
        yield TextField::new('adresse', 'Adresse');
        yield TextField::new('codePostal', 'Code postal');
        yield TextField::new('ville', 'Ville');
        yield TelephoneField::new('telephone', 'Téléphone');
        yield EmailField::new('email', 'Email');
        yield TextField::new('siret', 'SIRET')
            ->setHelp('14 chiffres');
        yield BooleanField::new('actif', 'Actif');

        $etablissementsField = AssociationField::new('etablissements', 'Restaurants')
            ->setFormTypeOption('by_reference', false);

        // Filter etablissements to user's organisation only
        if ($organisation !== null) {
            $etablissementsField->setQueryBuilder(
                fn (QueryBuilder $qb) => $qb
                    ->andWhere('entity.organisation = :organisation')
                    ->andWhere('entity.actif = true')
                    ->setParameter('organisation', $organisation)
                    ->orderBy('entity.nom', 'ASC')
            );
        }

        yield $etablissementsField;

        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Modifié le')
            ->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);

        if ($entityInstance instanceof Fournisseur) {
            $this->ensureOrganisationFournisseurLink($entityManager, $entityInstance);
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);

        if ($entityInstance instanceof Fournisseur) {
            $this->ensureOrganisationFournisseurLink($entityManager, $entityInstance);
        }
    }

    private function ensureOrganisationFournisseurLink(EntityManagerInterface $entityManager, Fournisseur $fournisseur): void
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        $organisation = $user?->getOrganisation();

        if ($organisation === null) {
            return;
        }

        // Check if OrganisationFournisseur link already exists
        if ($this->organisationFournisseurRepository->hasAccess($organisation, $fournisseur)) {
            return;
        }

        $orgFournisseur = new OrganisationFournisseur();
        $orgFournisseur->setOrganisation($organisation);
        $orgFournisseur->setFournisseur($fournisseur);
        $orgFournisseur->setActif(true);

        $entityManager->persist($orgFournisseur);
        $entityManager->flush();
    }
}
