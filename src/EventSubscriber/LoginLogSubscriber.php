<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\LoginLog;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class LoginLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getAuthenticatedToken()->getUser();
        if (!$user instanceof Utilisateur) {
            return;
        }

        $request = $event->getRequest();

        $log = new LoginLog();
        $log->setUtilisateur($user);
        $log->setEmail($user->getEmail());
        $log->setStatus('success');
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 500));

        $this->persistLog($log);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $email = (string) $request->get('_username', '');

        $log = new LoginLog();
        $log->setEmail($email);
        $log->setStatus('failure');
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 500));

        $this->persistLog($log);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if (!$user instanceof Utilisateur) {
            return;
        }

        $request = $event->getRequest();

        $log = new LoginLog();
        $log->setUtilisateur($user);
        $log->setEmail($user->getEmail());
        $log->setStatus('logout');
        $log->setIpAddress($request?->getClientIp());
        $log->setUserAgent(mb_substr((string) $request?->headers->get('User-Agent', ''), 0, 500));

        $this->persistLog($log);
    }

    private function persistLog(LoginLog $log): void
    {
        try {
            $this->em->persist($log);
            $this->em->flush();
        } catch (\Exception $e) {
            $this->logger->error('Failed to persist login log', [
                'email' => $log->getEmail(),
                'status' => $log->getStatus(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
