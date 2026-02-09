<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_tokens')]
class RefreshToken extends BaseRefreshToken
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): static
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function revoke(): static
    {
        $this->revokedAt = new \DateTimeImmutable();

        return $this;
    }
}
