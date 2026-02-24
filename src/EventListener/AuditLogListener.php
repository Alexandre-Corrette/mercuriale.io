<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\AuditLog;
use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Mercuriale;
use App\Entity\ProduitFournisseur;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
class AuditLogListener
{
    private const AUDITED_ENTITIES = [
        BonLivraison::class,
        Fournisseur::class,
        ProduitFournisseur::class,
        Mercuriale::class,
        Etablissement::class,
        Utilisateur::class,
    ];

    private const EXCLUDED_FIELDS = ['password', 'updatedAt', 'createdAt'];

    /** @var AuditLog[] */
    private array $pendingLogs = [];

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAudited($entity)) {
            return;
        }

        $this->pendingLogs[] = $this->createLog('create', $entity, $entity->getId());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAudited($entity)) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();
        $changeSet = $uow->getEntityChangeSet($entity);

        $changes = [];
        foreach ($changeSet as $field => $values) {
            if (in_array($field, self::EXCLUDED_FIELDS, true)) {
                continue;
            }
            $changes[$field] = [
                $this->formatValue($values[0]),
                $this->formatValue($values[1]),
            ];
        }

        if ($changes === []) {
            return;
        }

        $log = $this->createLog('update', $entity, $entity->getId());
        $log->setChanges($changes);

        $this->pendingLogs[] = $log;
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAudited($entity)) {
            return;
        }

        $this->pendingLogs[] = $this->createLog('delete', $entity, $entity->getId());
    }

    /**
     * @return AuditLog[]
     */
    public function getPendingLogs(): array
    {
        return $this->pendingLogs;
    }

    public function clearPendingLogs(): void
    {
        $this->pendingLogs = [];
    }

    private function isAudited(object $entity): bool
    {
        foreach (self::AUDITED_ENTITIES as $class) {
            if ($entity instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function createLog(string $action, object $entity, ?int $entityId): AuditLog
    {
        $user = $this->security->getUser();
        $request = $this->requestStack->getCurrentRequest();

        $log = new AuditLog();
        $log->setAction($action);
        $log->setEntityType($this->getShortClassName($entity));
        $log->setEntityId($entityId ?? 0);
        $log->setEntityLabel($this->getEntityLabel($entity));

        if ($user instanceof Utilisateur) {
            $log->setUtilisateur($user);
        }

        if ($request !== null) {
            $log->setIpAddress($request->getClientIp());
        }

        return $log;
    }

    private function getShortClassName(object $entity): string
    {
        $class = $entity::class;
        $pos = strrpos($class, '\\');

        return $pos !== false ? substr($class, $pos + 1) : $class;
    }

    private function getEntityLabel(object $entity): ?string
    {
        if (method_exists($entity, '__toString')) {
            try {
                return mb_substr((string) $entity, 0, 255);
            } catch (\Exception) {
                // Fall through
            }
        }

        return $this->getShortClassName($entity) . '#' . ($entity->getId() ?? '?');
    }

    private function formatValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            if (method_exists($value, 'getId')) {
                return $value::class . '#' . $value->getId();
            }

            return $value::class;
        }

        return $value;
    }
}
