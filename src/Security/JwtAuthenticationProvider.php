<?php

declare(strict_types=1);

namespace App\Security;

use App\DTO\TokenPair;
use App\Entity\RefreshToken;
use App\Repository\RefreshTokenRepository;
use App\Security\Exception\InvalidTokenException;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class JwtAuthenticationProvider implements AuthenticationProviderInterface
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $jwtAccessTokenTtl,
    ) {
    }

    public function authenticate(UserInterface $user): TokenPair
    {
        $accessToken = $this->jwtManager->create($user);

        $refreshToken = $this->refreshTokenManager->create();
        $refreshToken = RefreshToken::createForUserWithTtl(
            (string) $refreshToken->getRefreshToken(),
            $user,
            2592000, // 30 days
        );
        $this->refreshTokenManager->save($refreshToken);

        return new TokenPair(
            accessToken: $accessToken,
            refreshToken: (string) $refreshToken->getRefreshToken(),
            expiresIn: $this->jwtAccessTokenTtl,
        );
    }

    public function refreshToken(string $refreshToken): TokenPair
    {
        $token = $this->refreshTokenRepository->findOneBy(['refreshToken' => $refreshToken]);

        if ($token === null || !$token->isValid() || $token->isRevoked()) {
            throw new InvalidTokenException('Invalid or expired refresh token.');
        }

        // The actual refresh is handled by gesdinet's refresh_jwt authenticator.
        // This method is provided for the interface contract and future Keycloak migration.
        throw new InvalidTokenException('Use the /api/token/refresh endpoint with the refresh_jwt authenticator.');
    }

    public function revokeToken(string $refreshToken): void
    {
        $token = $this->refreshTokenRepository->findOneBy(['refreshToken' => $refreshToken]);

        if ($token === null) {
            throw new InvalidTokenException('Refresh token not found.');
        }

        $token->revoke();
        $this->entityManager->flush();
    }

    public function revokeAllUserTokens(UserInterface $user): int
    {
        return $this->refreshTokenRepository->revokeAllForUser($user->getUserIdentifier());
    }
}
