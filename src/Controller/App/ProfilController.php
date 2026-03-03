<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
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
}
