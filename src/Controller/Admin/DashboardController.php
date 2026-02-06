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
use App\Entity\OrganisationFournisseur;
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
            ->setTitle('<img src="/images/logo-rectangulaire-mercuriale.jpg" alt="Mercuriale.io" style="width: 200px; height: 45px; object-fit: cover; object-position: center;">')
            ->setFaviconPath('favicon.ico')
            ->setLocales(['fr' => 'Français']);
    }

    public function configureMenuItems(): iterable
    {
        // ========================================
        // SECTION 1 : Navigation principale (tous les utilisateurs)
        // ========================================
        yield MenuItem::linkToDashboard('Tableau de bord', 'fas fa-tachometer-alt');

        yield MenuItem::section('OPÉRATIONS');

        yield MenuItem::linkToUrl('Uploader un BL', 'fas fa-camera', '/app/bl/upload')
            ->setCssClass('menu-item-highlight');

        yield MenuItem::linkToCrud('Bons de livraison', 'fas fa-file-invoice', BonLivraison::class)
            ->setDefaultSort(['dateLivraison' => 'DESC']);

        yield MenuItem::linkToCrud('Lignes BL', 'fas fa-list', LigneBonLivraison::class);

        yield MenuItem::linkToUrl('Import mercuriale', 'fas fa-file-excel', '/app/mercuriale/import')
            ->setCssClass('menu-item-highlight');

        yield MenuItem::linkToCrud('Alertes', 'fas fa-exclamation-triangle', AlerteControle::class)
            ->setDefaultSort(['createdAt' => 'DESC']);

        // ========================================
        // SECTION 2 : Référentiels (ROLE_MANAGER et +)
        // ========================================
        yield MenuItem::section('RÉFÉRENTIELS')
            ->setPermission('ROLE_MANAGER');

        yield MenuItem::subMenu('Fournisseurs & Produits', 'fas fa-boxes')
            ->setPermission('ROLE_MANAGER')
            ->setSubItems([
                MenuItem::linkToCrud('Fournisseurs', 'fas fa-truck', Fournisseur::class),
                MenuItem::linkToCrud('Associations Fournisseurs', 'fas fa-link', OrganisationFournisseur::class),
                MenuItem::linkToCrud('Produits fournisseur', 'fas fa-box', ProduitFournisseur::class),
                MenuItem::linkToCrud('Mercuriale (prix)', 'fas fa-tags', Mercuriale::class)
                    ->setDefaultSort(['dateDebut' => 'DESC']),
            ]);

        yield MenuItem::subMenu('Catalogue', 'fas fa-book')
            ->setPermission('ROLE_MANAGER')
            ->setSubItems([
                MenuItem::linkToCrud('Produits', 'fas fa-apple-whole', Produit::class),
                MenuItem::linkToCrud('Catégories', 'fas fa-folder', CategorieProduit::class),
            ]);

        yield MenuItem::subMenu('Unités', 'fas fa-ruler-combined')
            ->setPermission('ROLE_MANAGER')
            ->setSubItems([
                MenuItem::linkToCrud('Unités', 'fas fa-ruler', Unite::class),
                MenuItem::linkToCrud('Conversions', 'fas fa-exchange-alt', ConversionUnite::class),
            ]);

        // ========================================
        // SECTION 3 : Administration (ROLE_ADMIN uniquement)
        // ========================================
        yield MenuItem::section('ADMINISTRATION')
            ->setPermission('ROLE_ADMIN');

        yield MenuItem::subMenu('Configuration', 'fas fa-cog')
            ->setPermission('ROLE_ADMIN')
            ->setSubItems([
                MenuItem::linkToCrud('Établissements', 'fas fa-store', Etablissement::class),
                MenuItem::linkToCrud('Utilisateurs', 'fas fa-users', Utilisateur::class),
                MenuItem::linkToCrud('Droits établissements', 'fas fa-user-shield', UtilisateurEtablissement::class),
                MenuItem::linkToCrud('Organisations', 'fas fa-sitemap', Organisation::class),
            ]);

        // ========================================
        // SECTION 4 : Déconnexion
        // ========================================
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
            ->addCssFile('css/admin.css');
    }
}
