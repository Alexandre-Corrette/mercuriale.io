<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AlerteControle;
use App\Entity\BonLivraison;
use App\Entity\CategorieProduit;
use App\Entity\ConversionUnite;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\LigneBonLivraison;
use App\Entity\Mercuriale;
use App\Entity\Organisation;
use App\Entity\Produit;
use App\Entity\ProduitFournisseur;
use App\Entity\Unite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEtablissement;
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
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Mercuriale.io')
            ->setFaviconPath('favicon.ico')
            ->setLocales(['fr' => 'Français']);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');

        yield MenuItem::section('Gestion des BL');
        yield MenuItem::linkToCrud('Bons de livraison', 'fa fa-file-invoice', BonLivraison::class);
        yield MenuItem::linkToCrud('Lignes BL', 'fa fa-list', LigneBonLivraison::class);
        yield MenuItem::linkToCrud('Alertes', 'fa fa-exclamation-triangle', AlerteControle::class);

        yield MenuItem::section('Mercuriale');
        yield MenuItem::linkToCrud('Prix négociés', 'fa fa-euro-sign', Mercuriale::class);
        yield MenuItem::linkToCrud('Produits fournisseur', 'fa fa-box', ProduitFournisseur::class);

        yield MenuItem::section('Référentiel');
        yield MenuItem::linkToCrud('Produits', 'fa fa-apple-whole', Produit::class);
        yield MenuItem::linkToCrud('Catégories', 'fa fa-folder', CategorieProduit::class);
        yield MenuItem::linkToCrud('Fournisseurs', 'fa fa-truck', Fournisseur::class);

        yield MenuItem::section('Configuration');
        yield MenuItem::linkToCrud('Établissements', 'fa fa-building', Etablissement::class);
        yield MenuItem::linkToCrud('Utilisateurs', 'fa fa-users', Utilisateur::class);
        yield MenuItem::linkToCrud('Droits établissements', 'fa fa-user-shield', UtilisateurEtablissement::class);
        yield MenuItem::linkToCrud('Organisations', 'fa fa-sitemap', Organisation::class);

        yield MenuItem::section('Unités');
        yield MenuItem::linkToCrud('Unités', 'fa fa-ruler', Unite::class);
        yield MenuItem::linkToCrud('Conversions', 'fa fa-exchange-alt', ConversionUnite::class);

        yield MenuItem::section('');
        yield MenuItem::linkToRoute('Retour à l\'app', 'fa fa-arrow-left', 'app_dashboard');
        yield MenuItem::linkToLogout('Déconnexion', 'fa fa-sign-out-alt');
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
            ->addCssFile('css/admin.css');
    }
}
