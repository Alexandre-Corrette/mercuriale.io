<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Unite;
use App\Enum\TypeUnite;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
class UniteCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Unite::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Unité')
            ->setEntityLabelInPlural('Unités')
            ->setSearchFields(['nom', 'code'])
            ->setDefaultSort(['ordre' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'Code')
            ->setRequired(true)
            ->setHelp('Ex: kg, L, p');
        yield TextField::new('nom', 'Nom')
            ->setRequired(true);
        yield ChoiceField::new('type', 'Type')
            ->setChoices([
                'Poids' => TypeUnite::POIDS,
                'Volume' => TypeUnite::VOLUME,
                'Quantité' => TypeUnite::QUANTITE,
            ])
            ->setRequired(true);
        yield IntegerField::new('ordre', 'Ordre')
            ->setHelp('Ordre d\'affichage');
    }
}
