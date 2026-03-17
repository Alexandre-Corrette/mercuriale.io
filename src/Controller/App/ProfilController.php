<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Utilisateur;
use App\Form\ChangePasswordType;
use App\Form\ProfilEditType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/profil')]
#[IsGranted('ROLE_USER')]
class ProfilController extends AbstractController
{
    #[Route('', name: 'app_profil_hub', methods: ['GET'])]
    public function hub(): Response
    {
        return $this->render('app/profil/hub.html.twig');
    }

    #[Route('/coordonnees', name: 'app_profil_coordonnees', methods: ['GET'])]
    public function coordonnees(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();
        $etablissements = $user->getEtablissements();

        return $this->render('app/profil/coordonnees.html.twig', [
            'user' => $user,
            'organisation' => $org,
            'etablissements' => $etablissements,
        ]);
    }

    #[Route('/modifier', name: 'app_profil_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $form = $this->createForm(ProfilEditType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'Vos informations ont ete mises a jour.');

            return $this->redirectToRoute('app_profil_coordonnees');
        }

        return $this->render('app/profil/edit.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/mot-de-passe', name: 'app_profil_password', methods: ['GET', 'POST'])]
    public function password(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();

            if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('error', 'Le mot de passe actuel est incorrect.');

                return $this->render('app/profil/password.html.twig', [
                    'form' => $form,
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $newPassword = $form->get('newPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            $em->flush();

            $this->addFlash('success', 'Votre mot de passe a ete modifie.');

            return $this->redirectToRoute('app_profil_coordonnees');
        }

        return $this->render('app/profil/password.html.twig', [
            'form' => $form,
        ]);
    }
}
