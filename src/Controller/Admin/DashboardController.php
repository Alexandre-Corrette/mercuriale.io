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
use App\Repository\AlerteControleRepository;
use App\Repository\BonLivraisonRepository;
use App\Repository\FournisseurRepository;
use App\Repository\MercurialeRepository;
use App\Repository\ProduitFournisseurRepository;
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
        private readonly BonLivraisonRepository $blRepo,
        private readonly AlerteControleRepository $alerteRepo,
        private readonly MercurialeRepository $mercurialeRepo,
        private readonly ProduitFournisseurRepository $produitRepo,
        private readonly FournisseurRepository $fournisseurRepo,
    ) {
    }

    public function index(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('admin/dashboard.html.twig', [
            'bl_count' => $this->blRepo->countByMonthForOrganisation($org),
            'alerte_count' => $this->alerteRepo->countNonTraiteesForOrganisation($org),
            'mercuriale_count' => $this->mercurialeRepo->countActiveForOrganisation($org),
            'produit_count' => $this->produitRepo->countActiveForOrganisation($org),
            'fournisseur_count' => $this->fournisseurRepo->countActiveForOrganisation($org),
            'recent_bls' => $this->blRepo->findRecentForOrganisation($org, 5),
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

        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            // ========================================
            // SUPER_ADMIN : sidebar complete
            // ========================================
            yield MenuItem::section('OPÉRATIONS');

            yield MenuItem::linkToUrl('Uploader un BL', 'fas fa-camera', '/app/bl/upload')
                ->setCssClass('menu-item-highlight');

            yield MenuItem::linkToCrud('Bons de livraison', 'fas fa-file-invoice', BonLivraison::class)
                ->setPermission('ROLE_SUPER_ADMIN')
                ->setDefaultSort(['dateLivraison' => 'DESC']);

            yield MenuItem::linkToCrud('Lignes BL', 'fas fa-list', LigneBonLivraison::class)
                ->setPermission('ROLE_SUPER_ADMIN');

            yield MenuItem::linkToUrl('Import mercuriale', 'fas fa-file-excel', '/app/mercuriale/import')
                ->setCssClass('menu-item-highlight');

            yield MenuItem::linkToCrud('Alertes', 'fas fa-exclamation-triangle', AlerteControle::class)
                ->setPermission('ROLE_SUPER_ADMIN')
                ->setDefaultSort(['createdAt' => 'DESC']);

            yield MenuItem::section('RÉFÉRENTIELS');

            yield MenuItem::subMenu('Fournisseurs & Produits', 'fas fa-boxes')
                ->setPermission('ROLE_SUPER_ADMIN')
                ->setSubItems([
                    MenuItem::linkToCrud('Fournisseurs', 'fas fa-truck', Fournisseur::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                    MenuItem::linkToCrud('Associations Fournisseurs', 'fas fa-link', OrganisationFournisseur::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                    MenuItem::linkToCrud('Produits', 'fas fa-box', ProduitFournisseur::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                    MenuItem::linkToCrud('Mercuriale (prix)', 'fas fa-tags', Mercuriale::class)
                        ->setPermission('ROLE_SUPER_ADMIN')
                        ->setDefaultSort(['dateDebut' => 'DESC']),
                ]);

            yield MenuItem::subMenu('Catalogue', 'fas fa-book')
                ->setPermission('ROLE_SUPER_ADMIN')
                ->setSubItems([
                    MenuItem::linkToCrud('Catalogue interne', 'fas fa-apple-whole', Produit::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                    MenuItem::linkToCrud('Catégories', 'fas fa-folder', CategorieProduit::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                ]);

            yield MenuItem::subMenu('Unités', 'fas fa-ruler-combined')
                ->setPermission('ROLE_SUPER_ADMIN')
                ->setSubItems([
                    MenuItem::linkToCrud('Unités', 'fas fa-ruler', Unite::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                    MenuItem::linkToCrud('Conversions', 'fas fa-exchange-alt', ConversionUnite::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                ]);

            yield MenuItem::section('ADMINISTRATION');

            yield MenuItem::subMenu('Configuration', 'fas fa-cog')
                ->setPermission('ROLE_SUPER_ADMIN')
                ->setSubItems([
                    MenuItem::linkToCrud('Établissements', 'fas fa-store', Etablissement::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                    MenuItem::linkToCrud('Utilisateurs', 'fas fa-users', Utilisateur::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                    MenuItem::linkToCrud('Droits établissements', 'fas fa-user-shield', UtilisateurEtablissement::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                    MenuItem::linkToCrud('Organisations', 'fas fa-sitemap', Organisation::class)
                        ->setPermission('ROLE_SUPER_ADMIN'),
                ]);
        }
        // ADMIN/MANAGER : pas d'items supplementaires (navigation par cards du dashboard)

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
            ->addCssFile('css/admin.css')
            ->addCssFile('css/admin-dashboard.css');
    }
}
