<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Etablissement;
use App\Twig\Extension\AppLayoutExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class EtablissementSwitchController extends AbstractController
{
    #[Route('/app/etablissement/switch/{id}', name: 'app_etablissement_switch')]
    public function __invoke(Etablissement $etablissement, Request $request): Response
    {
        $this->denyAccessUnlessGranted('VIEW', $etablissement);

        $request->getSession()->set(AppLayoutExtension::SESSION_KEY, $etablissement->getId());

        // Redirect back to previous page, fallback to dashboard
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('admin');
    }
}
