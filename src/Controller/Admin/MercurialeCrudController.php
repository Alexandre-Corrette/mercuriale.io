<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Mercuriale;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
class MercurialeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Mercuriale::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Prix négocié')
            ->setEntityLabelInPlural('Prix négociés')
            ->setDefaultSort(['dateDebut' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('produitFournisseur', 'Produit fournisseur')
            ->setRequired(true);
        yield AssociationField::new('etablissement', 'Établissement')
            ->setHelp('Laisser vide pour un prix groupe');
        yield MoneyField::new('prixNegocie', 'Prix négocié')
            ->setCurrency('EUR')
            ->setStoredAsCents(false)
            ->setNumDecimals(4)
            ->setRequired(true);
        yield DateField::new('dateDebut', 'Date début')
            ->setRequired(true);
        yield DateField::new('dateFin', 'Date fin')
            ->setHelp('Laisser vide si pas de fin');
        yield NumberField::new('seuilAlertePct', 'Seuil alerte %')
            ->setNumDecimals(2)
            ->setHelp('Écart % déclenchant une alerte');
        yield TextareaField::new('notes', 'Notes')
            ->hideOnIndex();
        yield AssociationField::new('createdBy', 'Créé par')
            ->hideOnForm();
        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Modifié le')
            ->hideOnForm();
    }
}
