<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Etablissement;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEtablissement;
use App\Service\SirenApiService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
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

            // Utilisateur
            $userNom = trim($request->request->getString('user_nom'));
            $userPrenom = trim($request->request->getString('user_prenom'));
            $userEmail = trim($request->request->getString('user_email'));
            $userPassword = $request->request->getString('user_password');
            $userPasswordConfirm = $request->request->getString('user_password_confirm');

            $errors = [];

            // Validate required fields
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

            // Check email uniqueness
            if ($userEmail !== '' && $em->getRepository(Utilisateur::class)->findOneBy(['email' => $userEmail])) {
                $errors[] = 'Cette adresse email est deja utilisee.';
            }

            if (!empty($errors)) {
                return $this->render('security/register.html.twig', [
                    'errors' => $errors,
                    'form_data' => $request->request->all(),
                ]);
            }

            $em->beginTransaction();
            try {
                // Create Organisation
                $organisation = new Organisation();
                $organisation->setNom($orgNom);
                if ($orgSiret !== '') {
                    $organisation->setSiret($orgSiret);
                }
                $em->persist($organisation);

                // Create Etablissement (siege)
                $etablissement = new Etablissement();
                $etablissement->setOrganisation($organisation);
                $etablissement->setNom($orgNom);
                if ($orgAdresse !== '') {
                    $etablissement->setAdresse($orgAdresse);
                }
                if ($orgCp !== '') {
                    $etablissement->setCodePostal($orgCp);
                }
                if ($orgVille !== '') {
                    $etablissement->setVille($orgVille);
                }
                $em->persist($etablissement);

                // Create Utilisateur
                $utilisateur = new Utilisateur();
                $utilisateur->setOrganisation($organisation);
                $utilisateur->setNom($userNom);
                $utilisateur->setPrenom($userPrenom);
                $utilisateur->setEmail($userEmail);
                $utilisateur->setRoles(['ROLE_USER', 'ROLE_GERANT']);
                $utilisateur->setPassword(
                    $passwordHasher->hashPassword($utilisateur, $userPassword)
                );
                $em->persist($utilisateur);

                // Link user to etablissement
                $ue = new UtilisateurEtablissement();
                $ue->setUtilisateur($utilisateur);
                $ue->setEtablissement($etablissement);
                $ue->setRole('ROLE_GERANT');
                $em->persist($ue);

                // Validate all entities
                $allErrors = [];
                foreach ([$organisation, $etablissement, $utilisateur, $ue] as $entity) {
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
                    ]);
                }

                $em->flush();
                $em->commit();

                $logger->info('Nouvelle inscription', [
                    'organisation' => $orgNom,
                    'siret' => $orgSiret,
                    'email' => $userEmail,
                ]);

                $this->addFlash('success', 'Compte cree avec succes ! Connectez-vous pour commencer.');

                return $this->redirectToRoute('app_login');
            } catch (\Exception $e) {
                $em->rollback();
                $logger->error('Erreur inscription', ['error' => $e->getMessage()]);

                return $this->render('security/register.html.twig', [
                    'errors' => ['Une erreur est survenue lors de la creation du compte.'],
                    'form_data' => $request->request->all(),
                ]);
            }
        }

        return $this->render('security/register.html.twig', [
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
