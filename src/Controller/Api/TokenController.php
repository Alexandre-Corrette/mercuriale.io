<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\UtilisateurRepository;
use App\Security\AuthenticationProviderInterface;
use App\Security\Exception\InvalidTokenException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TokenController extends AbstractController
{
    public function __construct(
        private readonly AuthenticationProviderInterface $authenticationProvider,
    ) {
    }

    #[Route('/api/token/revoke', name: 'api_token_revoke', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function revoke(Request $request): JsonResponse
    {
        $tokenValue = $request->cookies->get('refresh_token')
            ?? $request->toArray()['refresh_token'] ?? null;

        if ($tokenValue === null) {
            return $this->json(
                ['code' => Response::HTTP_BAD_REQUEST, 'message' => 'No refresh token provided.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $this->authenticationProvider->revokeToken($tokenValue);
        } catch (InvalidTokenException $e) {
            return $this->json(
                ['code' => Response::HTTP_NOT_FOUND, 'message' => $e->getMessage()],
                Response::HTTP_NOT_FOUND,
            );
        }

        $response = $this->json(['message' => 'Refresh token revoked successfully.']);
        $response->headers->clearCookie('refresh_token', '/api/token/refresh');

        return $response;
    }

    #[Route('/api/admin/tokens/revoke-user/{userId}', name: 'api_admin_revoke_user_tokens', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function revokeAllForUser(
        int $userId,
        UtilisateurRepository $utilisateurRepository,
    ): JsonResponse {
        $user = $utilisateurRepository->find($userId);

        if ($user === null) {
            return $this->json(
                ['code' => Response::HTTP_NOT_FOUND, 'message' => 'User not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $count = $this->authenticationProvider->revokeAllUserTokens($user);

        return $this->json([
            'message' => sprintf('%d refresh token(s) revoked for user %s.', $count, $user->getEmail()),
        ]);
    }
}
