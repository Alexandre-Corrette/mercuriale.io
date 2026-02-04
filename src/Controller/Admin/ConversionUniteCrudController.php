<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ConversionUnite;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
class ConversionUniteCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ConversionUnite::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Conversion d\'unité')
            ->setEntityLabelInPlural('Conversions d\'unités');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('uniteSource', 'Unité source')
            ->setRequired(true);
        yield AssociationField::new('uniteCible', 'Unité cible')
            ->setRequired(true);
        yield NumberField::new('facteur', 'Facteur')
            ->setNumDecimals(6)
            ->setRequired(true)
            ->setHelp('Ex: 1000 pour kg → g');
    }
}
