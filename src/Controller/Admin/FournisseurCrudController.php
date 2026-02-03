<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Fournisseur;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class FournisseurCrudController extends AbstractCrudController
{
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
            ->setDefaultSort(['nom' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('organisation', 'Organisation')
            ->setRequired(true);
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
        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Modifié le')
            ->hideOnForm();
    }
}
