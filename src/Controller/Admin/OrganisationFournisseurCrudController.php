<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\OrganisationFournisseur;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class OrganisationFournisseurCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return OrganisationFournisseur::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Association Fournisseur')
            ->setEntityLabelInPlural('Associations Fournisseurs')
            ->setSearchFields(['organisation.nom', 'fournisseur.nom', 'codeClient', 'contactCommercial'])
            ->setDefaultSort(['organisation.nom' => 'ASC', 'fournisseur.nom' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('organisation', 'Organisation')
            ->setRequired(true);
        yield AssociationField::new('fournisseur', 'Fournisseur')
            ->setRequired(true);
        yield TextField::new('codeClient', 'Code client')
            ->setHelp('Votre code client chez ce fournisseur');
        yield TextField::new('contactCommercial', 'Contact commercial');
        yield EmailField::new('emailCommande', 'Email commandes')
            ->setHelp('Email pour envoyer les commandes');
        yield BooleanField::new('actif', 'Actif');
        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Modifié le')
            ->hideOnForm();
    }
}
