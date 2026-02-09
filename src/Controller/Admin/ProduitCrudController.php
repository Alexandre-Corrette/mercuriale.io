<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Produit;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
class ProduitCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Produit::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Produit')
            ->setEntityLabelInPlural('Produits')
            ->setSearchFields(['nom', 'codeInterne'])
            ->setDefaultSort(['nom' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('codeInterne', 'Code interne');
        yield TextField::new('nom', 'Nom')
            ->setRequired(true);
        yield AssociationField::new('categorie', 'Catégorie');
        yield AssociationField::new('uniteBase', 'Unité de base')
            ->setRequired(true);
        yield BooleanField::new('actif', 'Actif');
        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Modifié le')
            ->hideOnForm();
    }
}
