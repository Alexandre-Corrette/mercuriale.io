<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LoginLog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
class LoginLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LoginLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Connexion')
            ->setEntityLabelInPlural('Stats Connexion')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['email', 'ipAddress', 'status'])
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
        yield TextField::new('email', 'Email');
        yield TextField::new('status', 'Statut')
            ->setTemplatePath('admin/fields/login_status_badge.html.twig');
        yield AssociationField::new('utilisateur', 'Utilisateur')->hideOnIndex();
        yield TextField::new('ipAddress', 'IP');
        yield TextField::new('userAgent', 'Navigateur')->hideOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices([
                'Connecté' => 'success',
                'Échec' => 'failure',
                'Déconnecté' => 'logout',
            ]))
            ->add(DateTimeFilter::new('createdAt'))
            ->add(TextFilter::new('email'));
    }
}
