<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::TERMINATE, priority: -100)]
class AuditLogFlushListener
{
    public function __construct(
        private readonly AuditLogListener $auditLogListener,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $logs = $this->auditLogListener->getPendingLogs();
        if ($logs === []) {
            return;
        }

        try {
            foreach ($logs as $log) {
                $this->em->persist($log);
            }
            $this->em->flush();
        } catch (\Exception $e) {
            $this->logger->error('Failed to flush audit logs', [
                'count' => count($logs),
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->auditLogListener->clearPendingLogs();
        }
    }
}
