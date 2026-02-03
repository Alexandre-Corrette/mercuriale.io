<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LigneBonLivraison;
use App\Enum\StatutControle;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class LigneBonLivraisonCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LigneBonLivraison::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ligne BL')
            ->setEntityLabelInPlural('Lignes BL')
            ->setSearchFields(['codeProduitBl', 'designationBl'])
            ->setDefaultSort(['ordre' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('bonLivraison', 'Bon de livraison')
            ->setRequired(true);
        yield TextField::new('codeProduitBl', 'Code produit');
        yield TextField::new('designationBl', 'Désignation')
            ->setRequired(true);
        yield AssociationField::new('produitFournisseur', 'Produit lié');
        yield NumberField::new('quantiteCommandee', 'Qté commandée')
            ->setNumDecimals(3);
        yield NumberField::new('quantiteLivree', 'Qté livrée')
            ->setNumDecimals(3)
            ->setRequired(true);
        yield AssociationField::new('unite', 'Unité')
            ->setRequired(true);
        yield MoneyField::new('prixUnitaire', 'Prix unitaire')
            ->setCurrency('EUR')
            ->setStoredAsCents(false)
            ->setNumDecimals(4)
            ->setRequired(true);
        yield MoneyField::new('totalLigne', 'Total')
            ->setCurrency('EUR')
            ->setStoredAsCents(false)
            ->setNumDecimals(4)
            ->hideOnForm();
        yield ChoiceField::new('statutControle', 'Statut contrôle')
            ->setChoices([
                'OK' => StatutControle::OK,
                'Écart qté' => StatutControle::ECART_QTE,
                'Écart prix' => StatutControle::ECART_PRIX,
                'Écarts multiples' => StatutControle::ECART_MULTIPLE,
                'Non contrôlé' => StatutControle::NON_CONTROLE,
            ])
            ->renderAsBadges([
                StatutControle::OK->value => 'success',
                StatutControle::ECART_QTE->value => 'warning',
                StatutControle::ECART_PRIX->value => 'warning',
                StatutControle::ECART_MULTIPLE->value => 'danger',
                StatutControle::NON_CONTROLE->value => 'secondary',
            ]);
        yield BooleanField::new('valide', 'Validé');
        yield IntegerField::new('ordre', 'Ordre')
            ->hideOnIndex();
    }
}
