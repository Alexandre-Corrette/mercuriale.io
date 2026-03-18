<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Repository\AlerteControleRepository;
use App\Twig\Extension\AppLayoutExtension;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AlerteControleRepository $alerteRepo,
        private readonly AppLayoutExtension $layoutExtension,
    ) {
    }

    public function index(): Response
    {
        $etablissement = $this->layoutExtension->getSelectedEtablissement();
        $alerteCount = $etablissement !== null
            ? $this->alerteRepo->countNonTraiteesForEtablissement($etablissement)
            : 0;

        return $this->render('admin/dashboard.html.twig', [
            'alerte_count' => $alerteCount,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="/images/logo-mercuriale-rectangulaire.png" alt="Mercuriale.io" class="sidebar-logo__img">')
            ->setFaviconPath('favicon.ico')
            ->setLocales(['fr' => 'Français']);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fas fa-tachometer-alt');
        yield MenuItem::section('');
        yield MenuItem::linkToLogout('Déconnexion', 'fas fa-sign-out-alt');
    }

    public function configureActions(): Actions
    {
        return Actions::new()
            ->add(Crud::PAGE_INDEX, Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DELETE)
            ->add(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN)
            ->add(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
            ->add(Crud::PAGE_NEW, Action::SAVE_AND_RETURN)
            ->add(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER);
    }

    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setDateFormat('dd/MM/yyyy')
            ->setDateTimeFormat('dd/MM/yyyy HH:mm')
            ->setTimezone('Europe/Paris')
            ->setNumberFormat('%.2d')
            ->setPaginatorPageSize(30)
            ->showEntityActionsInlined();
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('css/tokens.css')
            ->addCssFile('css/components.css')
            ->addCssFile('css/components/button.css')
            ->addCssFile('css/components/card.css')
            ->addCssFile('css/admin.css');
    }
}
