<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\RefreshTokenRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
class BlockRevokedRefreshTokenListener
{
    public function __construct(
        private readonly RefreshTokenRepository $refreshTokenRepository,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->getPathInfo() !== '/api/token/refresh' || !$request->isMethod('POST')) {
            return;
        }

        // Refresh token can come from cookie or request body
        $tokenValue = $request->cookies->get('refresh_token')
            ?? $request->toArray()['refresh_token'] ?? null;

        if ($tokenValue === null) {
            return;
        }

        $token = $this->refreshTokenRepository->findOneBy(['refreshToken' => $tokenValue]);

        if ($token !== null && $token->isRevoked()) {
            $event->setResponse(new JsonResponse(
                ['code' => Response::HTTP_UNAUTHORIZED, 'message' => 'Refresh token has been revoked.'],
                Response::HTTP_UNAUTHORIZED,
            ));
        }
    }
}
