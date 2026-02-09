<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProduitFournisseur;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
class ProduitFournisseurCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProduitFournisseur::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Produit')
            ->setEntityLabelInPlural('Produits')
            ->setSearchFields(['codeFournisseur', 'designationFournisseur', 'fournisseur.nom'])
            ->setDefaultSort(['designationFournisseur' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('fournisseur', 'Fournisseur')
            ->setRequired(true);
        yield TextField::new('codeFournisseur', 'Code fournisseur')
            ->setRequired(true);
        yield TextField::new('designationFournisseur', 'Désignation')
            ->setRequired(true);
        yield AssociationField::new('produit', 'Produit lié')
            ->setHelp('Produit interne correspondant');
        yield AssociationField::new('uniteAchat', 'Unité d\'achat')
            ->setRequired(true);
        yield NumberField::new('conditionnement', 'Conditionnement')
            ->setNumDecimals(3)
            ->setHelp('Quantité par unité d\'achat');
        yield BooleanField::new('actif', 'Actif');
        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Modifié le')
            ->hideOnForm();
    }
}
