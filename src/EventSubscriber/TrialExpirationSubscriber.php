<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class TrialExpirationSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = [
        'app_login',
        'app_logout',
        'app_register',
        'app_verification',
        'app_profil_hub',
        'app_profil_coordonnees',
        'api_siren_lookup',
        'admin', // EasyAdmin dashboard
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            return;
        }

        $organisation = $user->getOrganisation();
        if ($organisation === null) {
            return;
        }

        // Verified organisations have full access
        if ($organisation->isVerified()) {
            return;
        }

        // Trial still active — no blocking
        if ($organisation->isTrialActive()) {
            return;
        }

        // Trial expired + not verified — block access except allowed routes
        $route = $event->getRequest()->attributes->get('_route');

        if ($route === null || in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        // Allow webhook/public routes
        if (str_starts_with($route, 'webhook_') || str_starts_with($route, '_')) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_verification')
        ));
    }
}
