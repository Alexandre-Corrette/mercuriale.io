<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AlerteControle;
use App\Enum\StatutAlerte;
use App\Enum\TypeAlerte;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

class AlerteControleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AlerteControle::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Alerte')
            ->setEntityLabelInPlural('Alertes')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('statut')->setChoices([
                'Nouvelle' => StatutAlerte::NOUVELLE->value,
                'Vue' => StatutAlerte::VUE->value,
                'Acceptée' => StatutAlerte::ACCEPTEE->value,
                'Refusée' => StatutAlerte::REFUSEE->value,
            ]))
            ->add(ChoiceFilter::new('typeAlerte')->setChoices([
                'Écart quantité' => TypeAlerte::ECART_QUANTITE->value,
                'Écart prix' => TypeAlerte::ECART_PRIX->value,
                'Produit inconnu' => TypeAlerte::PRODUIT_INCONNU->value,
                'Prix manquant' => TypeAlerte::PRIX_MANQUANT->value,
            ]));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('ligneBl', 'Ligne BL')
            ->setRequired(true);
        yield ChoiceField::new('typeAlerte', 'Type')
            ->setChoices([
                'Écart quantité' => TypeAlerte::ECART_QUANTITE,
                'Écart prix' => TypeAlerte::ECART_PRIX,
                'Produit inconnu' => TypeAlerte::PRODUIT_INCONNU,
                'Prix manquant' => TypeAlerte::PRIX_MANQUANT,
            ])
            ->renderAsBadges([
                TypeAlerte::ECART_QUANTITE->value => 'warning',
                TypeAlerte::ECART_PRIX->value => 'danger',
                TypeAlerte::PRODUIT_INCONNU->value => 'info',
                TypeAlerte::PRIX_MANQUANT->value => 'secondary',
            ]);
        yield TextField::new('message', 'Message');
        yield NumberField::new('valeurAttendue', 'Valeur attendue')
            ->setNumDecimals(4)
            ->hideOnIndex();
        yield NumberField::new('valeurRecue', 'Valeur reçue')
            ->setNumDecimals(4)
            ->hideOnIndex();
        yield NumberField::new('ecartPct', 'Écart %')
            ->setNumDecimals(2);
        yield ChoiceField::new('statut', 'Statut')
            ->setChoices([
                'Nouvelle' => StatutAlerte::NOUVELLE,
                'Vue' => StatutAlerte::VUE,
                'Acceptée' => StatutAlerte::ACCEPTEE,
                'Refusée' => StatutAlerte::REFUSEE,
            ])
            ->renderAsBadges([
                StatutAlerte::NOUVELLE->value => 'danger',
                StatutAlerte::VUE->value => 'warning',
                StatutAlerte::ACCEPTEE->value => 'success',
                StatutAlerte::REFUSEE->value => 'secondary',
            ]);
        yield TextareaField::new('commentaire', 'Commentaire')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
        yield DateTimeField::new('traiteeAt', 'Traitée le')
            ->hideOnForm();
        yield AssociationField::new('traiteePar', 'Traitée par')
            ->hideOnForm();
    }
}
