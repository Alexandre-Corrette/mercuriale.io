<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BonLivraison;
use App\Enum\StatutBonLivraison;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BonLivraisonCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BonLivraison::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Bon de livraison')
            ->setEntityLabelInPlural('Bons de livraison')
            ->setSearchFields(['numeroBl', 'numeroCommande'])
            ->setDefaultSort(['dateLivraison' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('etablissement', 'Établissement')
            ->setRequired(true);
        yield AssociationField::new('fournisseur', 'Fournisseur')
            ->setRequired(true);
        yield TextField::new('numeroBl', 'N° BL');
        yield TextField::new('numeroCommande', 'N° Commande');
        yield DateField::new('dateLivraison', 'Date livraison')
            ->setRequired(true);
        yield ChoiceField::new('statut', 'Statut')
            ->setChoices([
                'Brouillon' => StatutBonLivraison::BROUILLON,
                'Validé' => StatutBonLivraison::VALIDE,
                'Anomalie' => StatutBonLivraison::ANOMALIE,
                'Archivé' => StatutBonLivraison::ARCHIVE,
            ])
            ->renderAsBadges([
                StatutBonLivraison::BROUILLON->value => 'secondary',
                StatutBonLivraison::VALIDE->value => 'success',
                StatutBonLivraison::ANOMALIE->value => 'danger',
                StatutBonLivraison::ARCHIVE->value => 'dark',
            ]);
        yield MoneyField::new('totalHt', 'Total HT')
            ->setCurrency('EUR')
            ->setStoredAsCents(false)
            ->setNumDecimals(2)
            ->hideOnForm();
        yield TextField::new('imagePath', 'Image')
            ->hideOnIndex();
        yield TextareaField::new('notes', 'Notes')
            ->hideOnIndex();
        yield AssociationField::new('createdBy', 'Créé par')
            ->hideOnForm();
        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
        yield DateTimeField::new('validatedAt', 'Validé le')
            ->hideOnForm();
        yield AssociationField::new('validatedBy', 'Validé par')
            ->hideOnForm();
    }
}
