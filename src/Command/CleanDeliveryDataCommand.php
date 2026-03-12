<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BonLivraison;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:clean-delivery-data',
    description: 'Nettoie les BL et fichiers uploadés pour repartir sur des fixtures propres',
)]
class CleanDeliveryDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Exécuter sans confirmation')
            ->addOption('keep-files', null, InputOption::VALUE_NONE, 'Ne pas supprimer les fichiers physiques');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $blRepo = $this->em->getRepository(BonLivraison::class);
        $allBl = $blRepo->findAll();
        $blCount = count($allBl);

        if ($blCount === 0) {
            $io->success('Aucun bon de livraison en base.');

            return Command::SUCCESS;
        }

        // Count lines and alerts
        $ligneCount = 0;
        $alerteCount = 0;
        $files = [];
        foreach ($allBl as $bl) {
            $ligneCount += $bl->getLignes()->count();
            foreach ($bl->getLignes() as $ligne) {
                $alerteCount += $ligne->getAlertes()->count();
            }
            if ($bl->getImagePath()) {
                $files[] = $bl->getImagePath();
            }
        }

        $io->warning(sprintf(
            'Suppression de %d BL, %d lignes, %d alertes, %d fichiers',
            $blCount,
            $ligneCount,
            $alerteCount,
            count($files),
        ));

        if (!$input->getOption('force')) {
            if (!$io->confirm('Confirmer la suppression ?', false)) {
                $io->note('Annulé. Utilisez --force pour exécuter sans confirmation.');

                return Command::SUCCESS;
            }
        }

        // Delete files
        $deletedFiles = 0;
        if (!$input->getOption('keep-files')) {
            $uploadDir = $this->projectDir . '/var/uploads/bon_livraison';
            foreach ($files as $relativePath) {
                $fullPath = $uploadDir . '/' . $relativePath;
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                    ++$deletedFiles;
                }
            }
        }

        // Delete BL (cascades to lignes and alertes via Doctrine)
        $this->em->beginTransaction();
        try {
            foreach ($allBl as $bl) {
                $this->em->remove($bl);
            }
            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            $this->logger->error('Erreur nettoyage BL', ['error' => $e->getMessage()]);
            $io->error('Erreur : ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->logger->info('Nettoyage BL effectué', [
            'bl' => $blCount,
            'lignes' => $ligneCount,
            'alertes' => $alerteCount,
            'fichiers' => $deletedFiles,
        ]);

        $io->success(sprintf(
            'Supprimé : %d BL, %d lignes, %d alertes, %d fichiers',
            $blCount,
            $ligneCount,
            $alerteCount,
            $deletedFiles,
        ));

        return Command::SUCCESS;
    }
}
