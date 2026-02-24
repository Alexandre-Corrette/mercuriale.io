<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CategorieProduit;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
class CategorieProduitCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CategorieProduit::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Catégorie')
            ->setEntityLabelInPlural('Catégories')
            ->setSearchFields(['nom', 'code'])
            ->setDefaultSort(['ordre' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'Code')
            ->setRequired(true);
        yield TextField::new('nom', 'Nom')
            ->setRequired(true);
        yield AssociationField::new('parent', 'Catégorie parente')
            ->setRequired(false);
        yield IntegerField::new('ordre', 'Ordre');
        yield AssociationField::new('enfants', 'Sous-catégories')
            ->onlyOnDetail();
    }
}
