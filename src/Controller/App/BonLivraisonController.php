<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\BonLivraison;
use App\Entity\ProduitFournisseur;
use App\Entity\Utilisateur;
use App\Enum\StatutBonLivraison;
use App\Repository\AlerteControleRepository;
use App\Repository\BonLivraisonRepository;
use App\Repository\ProduitFournisseurRepository;
use App\Service\BonLivraisonImageService;
use App\Twig\Extension\AppLayoutExtension;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Voter\EtablissementVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/bl')]
#[IsGranted('ROLE_USER')]
class BonLivraisonController extends AbstractController
{
    public function __construct(
        private readonly BonLivraisonImageService $imageService,
    ) {
    }

    #[Route('', name: 'app_bl_hub', methods: ['GET'])]
    public function hub(
        BonLivraisonRepository $blRepo,
        AlerteControleRepository $alerteRepo,
        AppLayoutExtension $layoutExtension,
    ): Response {
        $etablissement = $layoutExtension->getSelectedEtablissement();
        if (!$etablissement) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('app/bon_livraison/hub.html.twig', [
            'pending_count' => $blRepo->countAnomalieForEtablissement($etablissement),
            'alerte_count' => $alerteRepo->countNonTraiteesForEtablissement($etablissement),
        ]);
    }

    #[Route('/liste', name: 'app_bl_liste', methods: ['GET'])]
    public function liste(
        BonLivraisonRepository $blRepo,
        AppLayoutExtension $layoutExtension,
    ): Response {
        $etablissement = $layoutExtension->getSelectedEtablissement();
        if (!$etablissement) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted(EtablissementVoter::VIEW, $etablissement);

        $blData = $blRepo->findValidatedByEtablissementWithAlertCount($etablissement);

        return $this->render('app/bon_livraison/liste.html.twig', [
            'bons_livraison' => $blData,
            'bl_count' => count($blData),
            'etablissement' => $etablissement,
        ]);
    }

    #[Route('/alertes', name: 'app_bl_alertes', methods: ['GET'])]
    public function alertes(
        AlerteControleRepository $alerteRepo,
        AppLayoutExtension $layoutExtension,
    ): Response {
        $etablissement = $layoutExtension->getSelectedEtablissement();
        if (!$etablissement) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted(EtablissementVoter::VIEW, $etablissement);

        $alertes = $alerteRepo->findNonTraiteesForEtablissement($etablissement);

        return $this->render('app/bon_livraison/alertes.html.twig', [
            'alertes' => $alertes,
        ]);
    }

    #[Route('/en-attente', name: 'app_bl_pending', methods: ['GET'])]
    public function pending(
        BonLivraisonRepository $blRepo,
        AppLayoutExtension $layoutExtension,
    ): Response {
        $etablissement = $layoutExtension->getSelectedEtablissement();
        if (!$etablissement) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted(EtablissementVoter::VIEW, $etablissement);

        $blData = $blRepo->findAnomalieByEtablissementWithAlertCount($etablissement);

        $bonsLivraison = array_map(fn (array $row) => $row['bl'], $blData);

        $selectedBl = $bonsLivraison[0] ?? null;
        $selectedAlertCount = 0;
        if ($selectedBl !== null) {
            foreach ($selectedBl->getLignes() as $ligne) {
                $selectedAlertCount += $ligne->getAlertes()->count();
            }
        }

        return $this->render('app/bon_livraison/pending.html.twig', [
            'bons_livraison' => $bonsLivraison,
            'selected_bl' => $selectedBl,
            'selected_alert_count' => $selectedAlertCount,
        ]);
    }

    #[Route('/{id}/pending-detail', name: 'app_bl_pending_detail', methods: ['GET'])]
    public function pendingDetail(BonLivraison $bonLivraison): Response
    {
        if (!$this->isGranted(EtablissementVoter::VIEW, $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        $alertCount = 0;
        foreach ($bonLivraison->getLignes() as $ligne) {
            $alertCount += $ligne->getAlertes()->count();
        }

        return $this->render('app/bon_livraison/_pending_detail.html.twig', [
            'bonLivraison' => $bonLivraison,
            'alertCount' => $alertCount,
        ]);
    }

    #[Route('/{id}/detail', name: 'app_bl_show', methods: ['GET'])]
    public function show(BonLivraison $bonLivraison): Response
    {
        if (!$this->isGranted(EtablissementVoter::VIEW, $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        $alertCount = 0;
        foreach ($bonLivraison->getLignes() as $ligne) {
            $alertCount += $ligne->getAlertes()->count();
        }

        return $this->render('app/bon_livraison/show.html.twig', [
            'bonLivraison' => $bonLivraison,
            'alertCount' => $alertCount,
        ]);
    }

    #[Route('/{id}/image', name: 'app_bl_image', methods: ['GET'])]
    public function image(BonLivraison $bonLivraison): Response
    {
        if (!$this->isGranted(EtablissementVoter::VIEW, $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        return $this->imageService->getImageResponse($bonLivraison);
    }

    #[Route('/{id}/valider-force', name: 'app_bl_valider_force', methods: ['POST'])]
    public function validerForce(
        BonLivraison $bonLivraison,
        Request $request,
        EntityManagerInterface $em,
        ProduitFournisseurRepository $produitFournisseurRepo,
        LoggerInterface $logger,
    ): Response {
        if (!$this->isGranted(EtablissementVoter::MANAGE, $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('valider_force_bl_' . $bonLivraison->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de securite invalide.');

            return $this->redirectToRoute('app_bl_pending');
        }

        if ($bonLivraison->getStatut() !== StatutBonLivraison::ANOMALIE) {
            $this->addFlash('error', 'Ce BL ne peut pas etre valide depuis cette page.');

            return $this->redirectToRoute('app_bl_pending');
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $fournisseur = $bonLivraison->getFournisseur();
        $createdCount = 0;

        if ($fournisseur === null) {
            $this->addFlash('error', 'Ce BL n\'a pas de fournisseur associe, impossible de creer les produits.');

            return $this->redirectToRoute('app_bl_pending');
        }

        $em->beginTransaction();
        try {
            foreach ($bonLivraison->getLignes() as $ligne) {
                if ($ligne->getProduitFournisseur() !== null) {
                    continue;
                }

                $code = $ligne->getCodeProduitBl();
                if ($code === null || $code === '') {
                    continue;
                }

                // Check if product already exists for this supplier
                $existing = $produitFournisseurRepo->findByFournisseurAndCode($fournisseur, $code);
                if ($existing !== null) {
                    $ligne->setProduitFournisseur($existing);
                    continue;
                }

                // Auto-create ProduitFournisseur
                $produit = new ProduitFournisseur();
                $produit->setFournisseur($fournisseur);
                $produit->setCodeFournisseur($code);
                $produit->setDesignationFournisseur($ligne->getDesignationBl() ?? $code);
                $produit->setUniteAchat($ligne->getUnite());

                $em->persist($produit);
                $ligne->setProduitFournisseur($produit);
                $createdCount++;
            }

            $bonLivraison->setStatut(StatutBonLivraison::VALIDE);
            $bonLivraison->setValidatedAt(new \DateTimeImmutable());
            $bonLivraison->setValidatedBy($user);

            $em->flush();
            $em->commit();

            $logger->info('BL force-valide', [
                'bl_id' => $bonLivraison->getId(),
                'numero' => $bonLivraison->getNumeroBl(),
                'produits_crees' => $createdCount,
                'user' => $user->getUserIdentifier(),
            ]);

            $message = 'Bon de livraison valide avec succes.';
            if ($createdCount > 0) {
                $message .= sprintf(' %d produit(s) fournisseur cree(s).', $createdCount);
            }
            $this->addFlash('success', $message);
        } catch (\Exception $e) {
            $em->rollback();

            $logger->error('Erreur validation force BL', [
                'bl_id' => $bonLivraison->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('error', 'Erreur lors de la validation: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_bl_pending');
    }
}
