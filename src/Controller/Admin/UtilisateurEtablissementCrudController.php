<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\UtilisateurEtablissement;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class UtilisateurEtablissementCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return UtilisateurEtablissement::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Droit établissement')
            ->setEntityLabelInPlural('Droits établissements')
            ->setDefaultSort(['utilisateur' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('utilisateur', 'Utilisateur')
            ->setRequired(true);
        yield AssociationField::new('etablissement', 'Établissement')
            ->setRequired(true);
        yield ChoiceField::new('role', 'Rôle')
            ->setChoices([
                'Cuisinier' => 'ROLE_CUISINIER',
                'Gérant' => 'ROLE_GERANT',
                'Admin' => 'ROLE_ADMIN',
            ])
            ->setRequired(true);
        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
    }
}
