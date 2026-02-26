<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
class AuditLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AuditLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Action')
            ->setEntityLabelInPlural('Audit Trail')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['entityType', 'entityLabel', 'action'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Date');
        yield AssociationField::new('utilisateur', 'Utilisateur');
        yield TextField::new('action', 'Action')
            ->setTemplatePath('admin/fields/audit_action_badge.html.twig');
        yield TextField::new('entityType', 'Type');
        yield TextField::new('entityLabel', 'Élément');
        yield ArrayField::new('changes', 'Modifications')
            ->setTemplatePath('admin/fields/audit_changes.html.twig')
            ->onlyOnDetail();
        yield TextField::new('ipAddress', 'IP')->hideOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('action')->setChoices([
                'Création' => 'create',
                'Modification' => 'update',
                'Suppression' => 'delete',
            ]))
            ->add(ChoiceFilter::new('entityType')->setChoices([
                'Bon de livraison' => 'BonLivraison',
                'Fournisseur' => 'Fournisseur',
                'Produit' => 'ProduitFournisseur',
                'Mercuriale' => 'Mercuriale',
                'Établissement' => 'Etablissement',
                'Utilisateur' => 'Utilisateur',
            ]))
            ->add(DateTimeFilter::new('createdAt'))
            ->add(EntityFilter::new('utilisateur'));
    }
}
