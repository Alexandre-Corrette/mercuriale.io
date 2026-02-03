<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\BonLivraison;
use App\Enum\StatutBonLivraison;
use App\Service\Controle\ControleService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postUpdate)]
class BonLivraisonListener
{
    private array $pendingControles = [];

    public function __construct(
        private readonly ControleService $controleService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Détecte les changements de statut avant la mise à jour.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof BonLivraison) {
            return;
        }

        // Vérifier si le statut change vers VALIDE
        if ($args->hasChangedField('statut')) {
            $oldStatut = $args->getOldValue('statut');
            $newStatut = $args->getNewValue('statut');

            // Si passage vers VALIDE depuis BROUILLON, marquer pour contrôle
            if ($oldStatut === StatutBonLivraison::BROUILLON && $newStatut === StatutBonLivraison::VALIDE) {
                $this->pendingControles[$entity->getId()] = true;
                $this->logger->debug('BL marqué pour contrôle automatique', [
                    'bl_id' => $entity->getId(),
                    'old_statut' => $oldStatut->value,
                    'new_statut' => $newStatut->value,
                ]);
            }
        }
    }

    /**
     * Lance le contrôle après la mise à jour si nécessaire.
     */
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof BonLivraison) {
            return;
        }

        $blId = $entity->getId();

        // Vérifier si ce BL était marqué pour contrôle
        if (!isset($this->pendingControles[$blId])) {
            return;
        }

        // Nettoyer le marqueur
        unset($this->pendingControles[$blId]);

        try {
            $this->logger->info('Lancement contrôle automatique BL', ['bl_id' => $blId]);

            $nombreAlertes = $this->controleService->controlerBonLivraison($entity);

            $this->logger->info('Contrôle automatique BL terminé', [
                'bl_id' => $blId,
                'nombre_alertes' => $nombreAlertes,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur contrôle automatique BL', [
                'bl_id' => $blId,
                'error' => $e->getMessage(),
            ]);
            // Ne pas relancer l'exception pour ne pas bloquer la transaction
        }
    }
}
