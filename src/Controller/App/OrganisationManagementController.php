<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Exception\DuplicateSirenException;
use App\Exception\DuplicateSiretException;
use App\Form\EtablissementCreateType;
use App\Form\OrganisationCreateType;
use App\Repository\AbonnementRepository;
use App\Repository\UtilisateurOrganisationRepository;
use App\Service\OnboardingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/app/parametres/societes')]
class OrganisationManagementController extends AbstractController
{
    #[Route('', name: 'app_societes_index')]
    public function index(UtilisateurOrganisationRepository $uoRepository): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $userOrgs = $uoRepository->findByUtilisateur($user);

        return $this->render('app/parametres/societes/index.html.twig', [
            'user_organisations' => $userOrgs,
        ]);
    }

    #[Route('/{orgId}/etablissements/nouveau', name: 'app_societes_etablissement_new')]
    public function newEtablissement(
        int $orgId,
        Request $request,
        EntityManagerInterface $em,
        OnboardingService $onboardingService,
        UtilisateurOrganisationRepository $uoRepository,
        LoggerInterface $logger,
        RateLimiterFactory $onboardingLimiter,
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $organisation = $em->getRepository(Organisation::class)->find($orgId);
        if ($organisation === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted('ORG_MANAGE', $organisation);

        $form = $this->createForm(EtablissementCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $onboardingLimiter->create($request->getClientIp() ?? 'unknown');
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('error', 'Trop de requetes. Reessayez dans quelques instants.');

                return $this->redirectToRoute('app_societes_index');
            }

            $data = $form->getData();

            $em->beginTransaction();
            try {
                $etablissement = $onboardingService->addEtablissementToOrganisation(
                    $organisation,
                    $data['nom'],
                    $data['siret'] ?? null,
                    [
                        'adresse' => $data['adresse'] ?? '',
                        'codePostal' => $data['codePostal'] ?? '',
                        'ville' => $data['ville'] ?? '',
                    ],
                );
                $onboardingService->linkUserToEtablissement($user, $etablissement, 'ROLE_GERANT');

                $em->flush();
                $em->commit();

                $logger->info('Nouvel etablissement ajoute via parametres', [
                    'etablissement' => $data['nom'],
                    'organisation' => $organisation->getNom(),
                ]);

                $this->addFlash('success', 'Etablissement ajoute avec succes.');

                return $this->redirectToRoute('app_societes_index');
            } catch (DuplicateSiretException $e) {
                $em->rollback();
                $this->addFlash('error', $e->getMessage());
            } catch (\Exception $e) {
                $em->rollback();
                $logger->error('Erreur ajout etablissement', ['error' => $e->getMessage()]);
                $this->addFlash('error', 'Une erreur est survenue.');
            }
        }

        return $this->render('app/parametres/societes/etablissements/new.html.twig', [
            'form' => $form,
            'organisation' => $organisation,
        ]);
    }

    #[Route('/nouvelle', name: 'app_societes_new')]
    public function newOrganisation(
        Request $request,
        EntityManagerInterface $em,
        OnboardingService $onboardingService,
        AbonnementRepository $abonnementRepository,
        LoggerInterface $logger,
        RateLimiterFactory $onboardingLimiter,
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Gate behind Abonnement plan
        $currentOrg = $user->getOrganisation();
        $abonnement = $currentOrg !== null ? $abonnementRepository->findByOrganisation($currentOrg) : null;

        if ($abonnement === null || !$abonnement->canCreateOrganisation()) {
            $this->addFlash('error', 'Votre abonnement ne permet pas de creer plusieurs societes. Passez au plan Multi pour debloquer cette fonctionnalite.');

            return $this->redirectToRoute('app_societes_index');
        }

        $form = $this->createForm(OrganisationCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $onboardingLimiter->create($request->getClientIp() ?? 'unknown');
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('error', 'Trop de requetes. Reessayez dans quelques instants.');

                return $this->redirectToRoute('app_societes_index');
            }

            $data = $form->getData();

            $em->beginTransaction();
            try {
                [$organisation, $etablissement] = $onboardingService->createOrganisationWithEtablissement(
                    $data['nom'],
                    $data['siren'] ?? null,
                    $data['siret'] ?? null,
                    [
                        'adresse' => $data['adresse'] ?? '',
                        'codePostal' => $data['codePostal'] ?? '',
                        'ville' => $data['ville'] ?? '',
                    ],
                );
                $onboardingService->linkUserToOrganisation($user, $organisation);
                $onboardingService->linkUserToEtablissement($user, $etablissement, 'ROLE_GERANT');

                $em->flush();
                $em->commit();

                $logger->info('Nouvelle organisation creee via parametres', [
                    'organisation' => $data['nom'],
                    'user' => $user->getEmail(),
                ]);

                $this->addFlash('success', 'Societe creee avec succes.');

                return $this->redirectToRoute('app_societes_index');
            } catch (DuplicateSirenException|DuplicateSiretException $e) {
                $em->rollback();
                $this->addFlash('error', $e->getMessage());
            } catch (\Exception $e) {
                $em->rollback();
                $logger->error('Erreur creation organisation', ['error' => $e->getMessage()]);
                $this->addFlash('error', 'Une erreur est survenue.');
            }
        }

        return $this->render('app/parametres/societes/new.html.twig', [
            'form' => $form,
        ]);
    }
}
