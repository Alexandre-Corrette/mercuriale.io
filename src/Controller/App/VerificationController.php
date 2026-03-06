<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Utilisateur;
use App\Service\Stripe\StripeIdentityService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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

    #[Route('/app/verification/start', name: 'app_verification_start', methods: ['POST'])]
    public function start(
        Request $request,
        StripeIdentityService $identityService,
        LoggerInterface $logger,
    ): Response {
        if (!$this->isCsrfTokenValid('start_verification', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de securite invalide.');

            return $this->redirectToRoute('app_verification');
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $organisation = $user->getOrganisation();

        if ($organisation === null || $organisation->isVerified()) {
            return $this->redirectToRoute('app_verification');
        }

        try {
            $returnUrl = $this->generateUrl('app_verification', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $stripeUrl = $identityService->createVerificationSession($organisation, $returnUrl);

            return $this->redirect($stripeUrl);
        } catch (\Exception $e) {
            $logger->error('Failed to create Stripe verification session', [
                'error' => $e->getMessage(),
                'organisation_id' => $organisation->getId(),
            ]);

            $this->addFlash('error', 'Impossible de lancer la verification. Reessayez plus tard.');

            return $this->redirectToRoute('app_verification');
        }
    }
}
