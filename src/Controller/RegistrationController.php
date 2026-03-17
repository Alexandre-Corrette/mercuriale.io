<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Exception\DuplicateSirenException;
use App\Exception\DuplicateSiretException;
use App\Service\OnboardingService;
use App\Service\SirenApiService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
        LoggerInterface $logger,
        OnboardingService $onboardingService,
        Security $security,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('registration', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Token de securite invalide.');

                return $this->redirectToRoute('app_register');
            }

            // Organisation
            $orgNom = trim($request->request->getString('org_nom'));
            $orgSiret = trim($request->request->getString('org_siret'));
            $orgAdresse = trim($request->request->getString('org_adresse'));
            $orgCp = trim($request->request->getString('org_cp'));
            $orgVille = trim($request->request->getString('org_ville'));
            $etabNom = trim($request->request->getString('etab_nom'));

            // Utilisateur
            $userNom = trim($request->request->getString('user_nom'));
            $userPrenom = trim($request->request->getString('user_prenom'));
            $userEmail = trim($request->request->getString('user_email'));
            $userPassword = $request->request->getString('user_password');
            $userPasswordConfirm = $request->request->getString('user_password_confirm');

            $errors = [];

            if ($orgNom === '') {
                $errors[] = 'Le nom de l\'entreprise est obligatoire.';
            }
            if ($userNom === '') {
                $errors[] = 'Le nom est obligatoire.';
            }
            if ($userPrenom === '') {
                $errors[] = 'Le prenom est obligatoire.';
            }
            if ($userEmail === '') {
                $errors[] = 'L\'email est obligatoire.';
            }
            if ($userPassword === '') {
                $errors[] = 'Le mot de passe est obligatoire.';
            }
            if (strlen($userPassword) < 8) {
                $errors[] = 'Le mot de passe doit contenir au moins 8 caracteres.';
            }
            if ($userPassword !== $userPasswordConfirm) {
                $errors[] = 'Les mots de passe ne correspondent pas.';
            }

            if ($userEmail !== '' && $em->getRepository(Utilisateur::class)->findOneBy(['email' => $userEmail])) {
                $errors[] = 'Cette adresse email est deja utilisee.';
            }

            if (!empty($errors)) {
                return $this->render('security/register.html.twig', [
                    'errors' => $errors,
                    'form_data' => $request->request->all(),
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            // Extract SIREN from SIRET (first 9 digits)
            $orgSiren = ($orgSiret !== '' && strlen($orgSiret) === 14) ? substr($orgSiret, 0, 9) : null;

            $em->beginTransaction();
            try {
                [$organisation, $etablissement] = $onboardingService->createOrganisationWithEtablissement(
                    $orgNom,
                    $orgSiren,
                    $orgSiret !== '' ? $orgSiret : null,
                    [
                        'adresse' => $orgAdresse,
                        'codePostal' => $orgCp,
                        'ville' => $orgVille,
                    ],
                    $etabNom !== '' ? $etabNom : null,
                );

                // Create Utilisateur
                $utilisateur = new Utilisateur();
                $utilisateur->setOrganisation($organisation);
                $utilisateur->setNom($userNom);
                $utilisateur->setPrenom($userPrenom);
                $utilisateur->setEmail($userEmail);
                $utilisateur->setRoles(['ROLE_ADMIN']);
                $utilisateur->setPassword(
                    $passwordHasher->hashPassword($utilisateur, $userPassword)
                );
                $em->persist($utilisateur);

                // Link user to organisation + etablissement
                $onboardingService->linkUserToOrganisation($utilisateur, $organisation);
                $onboardingService->linkUserToEtablissement($utilisateur, $etablissement, 'ROLE_ADMIN');

                // Validate all entities
                $allErrors = [];
                foreach ([$organisation, $etablissement, $utilisateur] as $entity) {
                    $violations = $validator->validate($entity);
                    foreach ($violations as $violation) {
                        $allErrors[] = $violation->getMessage();
                    }
                }

                if (!empty($allErrors)) {
                    $em->rollback();

                    return $this->render('security/register.html.twig', [
                        'errors' => $allErrors,
                        'form_data' => $request->request->all(),
                    ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
                }

                $em->flush();
                $em->commit();

                $logger->info('Nouvelle inscription', [
                    'organisation' => $orgNom,
                    'siret' => $orgSiret,
                    'email' => $userEmail,
                ]);

                // Auto-login and redirect to step 3
                $security->login($utilisateur, 'form_login', 'main');

                return $this->redirectToRoute('app_register_step3');
            } catch (DuplicateSirenException|DuplicateSiretException $e) {
                $em->rollback();

                return $this->render('security/register.html.twig', [
                    'errors' => [$e->getMessage()],
                    'form_data' => $request->request->all(),
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            } catch (\Exception $e) {
                $em->rollback();
                $logger->error('Erreur inscription', ['error' => $e->getMessage()]);

                return $this->render('security/register.html.twig', [
                    'errors' => ['Une erreur est survenue lors de la creation du compte.'],
                    'form_data' => $request->request->all(),
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }
        }

        return $this->render('security/register.html.twig', [
            'errors' => [],
            'form_data' => [],
        ]);
    }

    #[Route('/inscription/etablissement', name: 'app_register_step3', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function registerStep3(
        Request $request,
        EntityManagerInterface $em,
        OnboardingService $onboardingService,
        ValidatorInterface $validator,
        LoggerInterface $logger,
        RateLimiterFactory $onboardingLimiter,
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $limiter = $onboardingLimiter->create($request->getClientIp() ?? 'unknown');
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('error', 'Trop de requetes. Reessayez dans quelques instants.');

                return $this->redirectToRoute('app_register_step3');
            }

            if (!$this->isCsrfTokenValid('registration_step3', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Token de securite invalide.');

                return $this->redirectToRoute('app_register_step3');
            }

            $nom = trim($request->request->getString('etab_nom'));
            $siret = trim($request->request->getString('etab_siret'));
            $adresse = trim($request->request->getString('etab_adresse'));
            $cp = trim($request->request->getString('etab_cp'));
            $ville = trim($request->request->getString('etab_ville'));

            $errors = [];
            if ($nom === '') {
                $errors[] = 'Le nom de l\'etablissement est obligatoire.';
            }

            if (!empty($errors)) {
                return $this->render('security/register_step3.html.twig', [
                    'errors' => $errors,
                    'form_data' => $request->request->all(),
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $em->beginTransaction();
            try {
                $organisation = $user->getOrganisation();
                $etablissement = $onboardingService->addEtablissementToOrganisation(
                    $organisation,
                    $nom,
                    $siret !== '' ? $siret : null,
                    [
                        'adresse' => $adresse,
                        'codePostal' => $cp,
                        'ville' => $ville,
                    ],
                );
                $onboardingService->linkUserToEtablissement($user, $etablissement, 'ROLE_GERANT');

                $violations = $validator->validate($etablissement);
                if (\count($violations) > 0) {
                    $em->rollback();
                    $validationErrors = [];
                    foreach ($violations as $violation) {
                        $validationErrors[] = $violation->getMessage();
                    }

                    return $this->render('security/register_step3.html.twig', [
                        'errors' => $validationErrors,
                        'form_data' => $request->request->all(),
                    ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
                }

                $em->flush();
                $em->commit();

                $logger->info('Nouvel etablissement ajoute', [
                    'etablissement' => $nom,
                    'organisation' => $organisation->getNom(),
                ]);

                $this->addFlash('success', 'Etablissement ajoute avec succes !');

                return $this->redirectToRoute('app_register_step3');
            } catch (DuplicateSiretException $e) {
                $em->rollback();

                return $this->render('security/register_step3.html.twig', [
                    'errors' => [$e->getMessage()],
                    'form_data' => $request->request->all(),
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            } catch (\Exception $e) {
                $em->rollback();
                $logger->error('Erreur ajout etablissement', ['error' => $e->getMessage()]);

                return $this->render('security/register_step3.html.twig', [
                    'errors' => ['Une erreur est survenue.'],
                    'form_data' => $request->request->all(),
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }
        }

        return $this->render('security/register_step3.html.twig', [
            'errors' => [],
            'form_data' => [],
        ]);
    }

    #[Route('/siren/lookup', name: 'api_siren_lookup', methods: ['GET'])]
    public function sirenLookup(
        Request $request,
        SirenApiService $sirenApi,
        RateLimiterFactory $anonymousApiLimiter,
    ): JsonResponse {
        $limiter = $anonymousApiLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de requetes, reessayez dans quelques instants.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $query = trim($request->query->getString('q'));
        if ($query === '') {
            return new JsonResponse(['error' => 'Le numero SIRET/SIREN est requis.'], Response::HTTP_BAD_REQUEST);
        }

        $result = $sirenApi->lookup($query);
        if ($result === null) {
            return new JsonResponse(['error' => 'Aucune entreprise trouvee pour ce numero.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result);
    }
}
