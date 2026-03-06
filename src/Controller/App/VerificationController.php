<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class VerificationController extends AbstractController
{
    #[Route('/app/verification', name: 'app_verification', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $organisation = $user->getOrganisation();

        return $this->render('app/verification/index.html.twig', [
            'organisation' => $organisation,
        ]);
    }
}
