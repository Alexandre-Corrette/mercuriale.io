<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Organisation;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class OrganisationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Organisation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Organisation')
            ->setEntityLabelInPlural('Organisations')
            ->setSearchFields(['nom', 'siret'])
            ->setDefaultSort(['nom' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('nom', 'Nom')
            ->setRequired(true);
        yield TextField::new('siret', 'SIRET')
            ->setHelp('14 chiffres');
        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Modifié le')
            ->hideOnForm();
    }
}
