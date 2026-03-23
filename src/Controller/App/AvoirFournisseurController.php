<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\AvoirFournisseur;
use App\Entity\BonLivraison;
use App\Entity\LigneAvoir;
use App\Entity\Utilisateur;
use App\Enum\MotifAvoir;
use App\Enum\StatutAvoir;
use App\Enum\StatutBonLivraison;
use App\Enum\TypeAlerte;
use App\Repository\AvoirFournisseurRepository;
use App\Service\AvoirWorkflowService;
use App\Twig\Extension\AppLayoutExtension;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Voter\EtablissementVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/avoirs')]
#[IsGranted('ROLE_USER')]
#[IsGranted('VERIFIED_FEATURE')]
class AvoirFournisseurController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AvoirFournisseurRepository $avoirRepo,
        private readonly AvoirWorkflowService $workflowService,
        private readonly RateLimiterFactory $avoirCreateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'app_avoirs_hub', methods: ['GET'])]
    public function hub(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('app/avoir/hub.html.twig', [
            'demande_count' => $this->avoirRepo->countByStatutForOrganisation($org, StatutAvoir::DEMANDE),
            'recu_count' => $this->avoirRepo->countByStatutForOrganisation($org, StatutAvoir::RECU),
        ]);
    }

    #[Route('/liste', name: 'app_avoirs_liste', methods: ['GET'])]
    public function liste(
        AppLayoutExtension $layoutExtension,
        Request $request,
    ): Response {
        $etablissement = $layoutExtension->getSelectedEtablissement();
        if (!$etablissement) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted(EtablissementVoter::VIEW, $etablissement);

        $statutFilter = $request->query->getString('statut');
        $statut = $statutFilter !== '' ? StatutAvoir::tryFrom($statutFilter) : null;

        $avoirs = $this->avoirRepo->findForEtablissementWithDetails($etablissement, $statut);

        return $this->render('app/avoir/liste.html.twig', [
            'avoirs' => $avoirs,
            'avoir_count' => count($avoirs),
            'statut_filter' => $statut,
            'statuts' => StatutAvoir::cases(),
        ]);
    }

    #[Route('/{id}', name: 'app_avoir_show', methods: ['GET'])]
    public function show(AvoirFournisseur $avoir): Response
    {
        if (!$this->isGranted(EtablissementVoter::VIEW, $avoir->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('app/avoir/show.html.twig', [
            'avoir' => $avoir,
        ]);
    }

    #[Route('/{id}/enregistrer', name: 'app_avoir_enregistrer', methods: ['GET', 'POST'])]
    public function enregistrer(AvoirFournisseur $avoir, Request $request): Response
    {
        if (!$this->isGranted(EtablissementVoter::MANAGE, $avoir->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->workflowService->canTransition($avoir, StatutAvoir::RECU)) {
            $this->addFlash('error', 'Cet avoir ne peut pas être enregistré dans son statut actuel.');

            return $this->redirectToRoute('app_avoir_show', ['id' => $avoir->getIdAsString()]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('avoir_enregistrer_' . $avoir->getIdAsString(), $request->request->getString('_token'))) {
                $this->addFlash('error', 'Token CSRF invalide. Veuillez réessayer.');

                return $this->redirectToRoute('app_avoir_enregistrer', ['id' => $avoir->getIdAsString()]);
            }

            $reference = trim($request->request->getString('reference'));
            $montantHt = $request->request->getString('montant_ht');
            $montantTva = $request->request->getString('montant_tva');
            $montantTtc = $request->request->getString('montant_ttc');

            /** @var Utilisateur $user */
            $user = $this->getUser();

            try {
                $this->workflowService->enregistrer(
                    $avoir,
                    $reference,
                    $user,
                    $montantHt !== '' ? $montantHt : null,
                    $montantTva !== '' ? $montantTva : null,
                    $montantTtc !== '' ? $montantTtc : null,
                );
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('app_avoir_enregistrer', ['id' => $avoir->getIdAsString()]);
            }

            $this->addFlash('success', 'Avoir enregistré avec succès.');

            return $this->redirectToRoute('app_avoir_show', ['id' => $avoir->getIdAsString()]);
        }

        return $this->render('app/avoir/enregistrer.html.twig', [
            'avoir' => $avoir,
        ]);
    }

    #[Route('/{id}/imputer', name: 'app_avoir_imputer', methods: ['POST'])]
    public function imputer(AvoirFournisseur $avoir, Request $request): Response
    {
        if (!$this->isGranted(EtablissementVoter::MANAGE, $avoir->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('avoir_imputer_' . $avoir->getIdAsString(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_avoir_show', ['id' => $avoir->getIdAsString()]);
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        try {
            $this->workflowService->imputer($avoir, $user);
        } catch (\LogicException | \InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_avoir_show', ['id' => $avoir->getIdAsString()]);
        }

        $this->addFlash('success', 'Avoir imputé avec succès.');

        return $this->redirectToRoute('app_avoir_show', ['id' => $avoir->getIdAsString()]);
    }

    #[Route('/{id}/refuser', name: 'app_avoir_refuser', methods: ['POST'])]
    public function refuser(AvoirFournisseur $avoir, Request $request): Response
    {
        if (!$this->isGranted(EtablissementVoter::MANAGE, $avoir->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('avoir_refuser_' . $avoir->getIdAsString(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_avoir_show', ['id' => $avoir->getIdAsString()]);
        }

        $commentaire = trim($request->request->getString('commentaire'));

        /** @var Utilisateur $user */
        $user = $this->getUser();

        try {
            $this->workflowService->refuser($avoir, $commentaire, $user);
        } catch (\LogicException | \InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_avoir_show', ['id' => $avoir->getIdAsString()]);
        }

        $this->addFlash('success', 'Avoir marqué comme refusé.');

        return $this->redirectToRoute('app_avoir_show', ['id' => $avoir->getIdAsString()]);
    }

    #[Route('/{id}/annuler', name: 'app_avoir_annuler', methods: ['POST'])]
    public function annuler(AvoirFournisseur $avoir, Request $request): Response
    {
        if (!$this->isGranted(EtablissementVoter::MANAGE, $avoir->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('avoir_annuler_' . $avoir->getIdAsString(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_avoir_show', ['id' => $avoir->getIdAsString()]);
        }

        $commentaire = trim($request->request->getString('commentaire'));

        /** @var Utilisateur $user */
        $user = $this->getUser();

        try {
            $this->workflowService->annuler($avoir, $commentaire, $user);
        } catch (\LogicException | \InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_avoir_show', ['id' => $avoir->getIdAsString()]);
        }

        $this->addFlash('success', 'Avoir annulé.');

        return $this->redirectToRoute('app_avoirs_liste');
    }

    #[Route('/demande/{id}', name: 'app_avoir_demande', methods: ['GET', 'POST'])]
    public function demande(BonLivraison $bonLivraison, Request $request): Response
    {
        // Vérifier accès à l'établissement
        if (!$this->isGranted(EtablissementVoter::MANAGE, $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        // Le BL doit être en statut ANOMALIE
        if ($bonLivraison->getStatut() !== StatutBonLivraison::ANOMALIE) {
            $this->addFlash('error', 'Seuls les BL en anomalie peuvent faire l\'objet d\'une demande d\'avoir.');

            return $this->redirectToRoute('app_bl_pending');
        }

        // Collecter les lignes en alerte (ECART_PRIX ou ECART_QUANTITE)
        $lignesEnAlerte = [];
        foreach ($bonLivraison->getLignes() as $ligne) {
            foreach ($ligne->getAlertes() as $alerte) {
                if (\in_array($alerte->getTypeAlerte(), [TypeAlerte::ECART_PRIX, TypeAlerte::ECART_QUANTITE], true)) {
                    $prixBl = $ligne->getPrixUnitaire();
                    $prixMercuriale = $alerte->getValeurAttendue();
                    $ecartUnitaire = $prixBl !== null && $prixMercuriale !== null
                        ? bcsub($prixBl, $prixMercuriale, 4)
                        : '0';
                    // Prendre la valeur absolue de l'écart
                    if (bccomp($ecartUnitaire, '0', 4) < 0) {
                        $ecartUnitaire = bcmul($ecartUnitaire, '-1', 4);
                    }
                    $quantite = $ligne->getQuantiteLivree() ?? '0';
                    $montantLigne = bcmul($quantite, $ecartUnitaire, 2);

                    $lignesEnAlerte[] = [
                        'ligne' => $ligne,
                        'alerte' => $alerte,
                        'ecartUnitaire' => $ecartUnitaire,
                        'montantLigne' => $montantLigne,
                    ];
                    break; // Une seule alerte par ligne suffit
                }
            }
        }

        // POST : créer l'avoir
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('avoir_demande_' . $bonLivraison->getId(), $request->request->getString('_token'))) {
                $this->addFlash('error', 'Token CSRF invalide. Veuillez réessayer.');

                return $this->redirectToRoute('app_avoir_demande', ['id' => $bonLivraison->getId()]);
            }

            // Rate limiting
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $limiter = $this->avoirCreateLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('error', 'Trop de demandes d\'avoir. Veuillez patienter.');

                return $this->redirectToRoute('app_avoir_demande', ['id' => $bonLivraison->getId()]);
            }

            // Récupérer le motif
            $motifValue = $request->request->getString('motif');
            $motif = MotifAvoir::tryFrom($motifValue);
            if ($motif === null) {
                $this->addFlash('error', 'Motif invalide.');

                return $this->redirectToRoute('app_avoir_demande', ['id' => $bonLivraison->getId()]);
            }

            // Récupérer les lignes cochées
            /** @var string[] $selectedLigneIds */
            $selectedLigneIds = $request->request->all('lignes');
            if (empty($selectedLigneIds)) {
                $this->addFlash('error', 'Veuillez sélectionner au moins une ligne.');

                return $this->redirectToRoute('app_avoir_demande', ['id' => $bonLivraison->getId()]);
            }

            $commentaire = $request->request->getString('commentaire');

            // Créer l'avoir
            $avoir = new AvoirFournisseur();
            $avoir->setFournisseur($bonLivraison->getFournisseur());
            $avoir->setEtablissement($bonLivraison->getEtablissement());
            $avoir->setBonLivraison($bonLivraison);
            $avoir->setStatut(StatutAvoir::DEMANDE);
            $avoir->setMotif($motif);
            $avoir->setDemandeLe(new \DateTimeImmutable());
            $avoir->setCreatedBy($user);
            if ($commentaire !== '') {
                $avoir->setCommentaire($commentaire);
            }

            // Ajouter les lignes sélectionnées
            $totalHt = '0';
            $selectedIds = array_map('intval', $selectedLigneIds);
            foreach ($lignesEnAlerte as $data) {
                if (\in_array($data['ligne']->getId(), $selectedIds, true)) {
                    $ligneAvoir = new LigneAvoir();
                    $ligneAvoir->setDesignation($data['ligne']->getDesignationBl() ?? '');
                    $ligneAvoir->setQuantite($data['ligne']->getQuantiteLivree() ?? '0');
                    $ligneAvoir->setPrixUnitaire($data['ecartUnitaire']);
                    $ligneAvoir->setMontantLigne($data['montantLigne']);

                    // Lier le produit si disponible
                    $produitFournisseur = $data['ligne']->getProduitFournisseur();
                    if ($produitFournisseur !== null) {
                        $ligneAvoir->setProduit($produitFournisseur->getProduit());
                    }

                    $avoir->addLigne($ligneAvoir);
                    $totalHt = bcadd($totalHt, $data['montantLigne'], 2);
                }
            }

            $avoir->setMontantHt($totalHt);

            // BL anomalie traitée → passe en VALIDE (avoir couvre l'écart)
            $bonLivraison->setStatut(StatutBonLivraison::VALIDE);

            $this->entityManager->persist($avoir);
            $this->entityManager->flush();

            $this->logger->info('Demande d\'avoir créée', [
                'avoir_id' => $avoir->getIdAsString(),
                'bl_id' => $bonLivraison->getId(),
                'motif' => $motif->value,
                'montant_ht' => $totalHt,
                'nb_lignes' => $avoir->getNombreLignes(),
                'user_id' => $user->getId(),
            ]);

            $this->addFlash('success', 'Demande d\'avoir créée avec succès.');

            return $this->redirectToRoute('app_bl_pending');
        }

        // GET : afficher le formulaire
        // Lignes pré-sélectionnées depuis le panneau pending (query params)
        $preselected = $request->query->all('lignes');
        $preselectedIds = !empty($preselected) ? array_map('intval', $preselected) : null;

        return $this->render('app/avoir/demande.html.twig', [
            'bonLivraison' => $bonLivraison,
            'lignesEnAlerte' => $lignesEnAlerte,
            'motifs' => MotifAvoir::cases(),
            'preselectedIds' => $preselectedIds,
        ]);
    }
}
