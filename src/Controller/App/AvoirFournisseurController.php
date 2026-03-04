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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/avoirs')]
#[IsGranted('ROLE_USER')]
class AvoirFournisseurController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactory $avoirCreateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/demande/{id}', name: 'app_avoir_demande', methods: ['GET', 'POST'])]
    public function demande(BonLivraison $bonLivraison, Request $request): Response
    {
        // Vérifier accès à l'établissement
        if (!$this->isGranted('MANAGE', $bonLivraison->getEtablissement())) {
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
