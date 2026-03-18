<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\NoActiveOrganisationException;
use App\Exception\OrganisationAccessRevokedException;
use App\Service\OrganisationContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Validates the active Organisation context on every /app/ request.
 * Redirects to org selection page if no context or if access was revoked.
 */
class OrganisationContextSubscriber implements EventSubscriberInterface
{
    private const EXCLUDED_ROUTES = [
        'app_select_organisation',
        'app_switch_context',
        'app_etablissement_switch',
        'app_register',
        'app_register_step3',
        'app_login',
        'app_logout',
    ];

    public function __construct(
        private readonly OrganisationContext $organisationContext,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Only check /app/ routes
        if (!str_starts_with($path, '/app/')) {
            return;
        }

        // Skip excluded routes to avoid redirect loops
        $route = $request->attributes->get('_route');
        if ($route !== null && \in_array($route, self::EXCLUDED_ROUTES, true)) {
            return;
        }

        // Only check for authenticated users
        $user = $this->security->getUser();
        if ($user === null) {
            return;
        }

        try {
            $this->organisationContext->getActiveOrganisation();
        } catch (NoActiveOrganisationException) {
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('app_select_organisation')
            ));
        } catch (OrganisationAccessRevokedException) {
            $request->getSession()->getFlashBag()->add(
                'error',
                'Votre accès à cette société a été révoqué.'
            );
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('app_select_organisation')
            ));
        }
    }
}
