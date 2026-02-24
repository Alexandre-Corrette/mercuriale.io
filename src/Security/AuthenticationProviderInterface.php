<?php

declare(strict_types=1);

namespace App\Security;

use App\DTO\TokenPair;
use Symfony\Component\Security\Core\User\UserInterface;

interface AuthenticationProviderInterface
{
    public function authenticate(UserInterface $user): TokenPair;

    public function refreshToken(string $refreshToken): TokenPair;

    public function revokeToken(string $refreshToken): void;

    public function revokeAllUserTokens(UserInterface $user): int;
}
