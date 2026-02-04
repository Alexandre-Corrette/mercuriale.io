<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\StatutImport;
use App\Repository\MercurialeImportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-expired-imports',
    description: 'Clean up expired mercuriale imports',
)]
class CleanupExpiredImportsCommand extends Command
{
    public function __construct(
        private readonly MercurialeImportRepository $importRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without making changes')
            ->addOption('delete-old', null, InputOption::VALUE_NONE, 'Delete imports older than 24 hours')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $deleteOld = $input->getOption('delete-old');

        $io->title('Cleanup Expired Mercuriale Imports');

        // Mark expired imports
        $expiredImports = $this->importRepository->findExpired();
        $markedCount = 0;

        foreach ($expiredImports as $import) {
            $io->text(sprintf(
                '  - Marking as expired: %s (%s)',
                $import->getOriginalFilename(),
                $import->getIdAsString(),
            ));

            if (!$dryRun) {
                $import->setStatus(StatutImport::EXPIRED);
            }
            ++$markedCount;
        }

        if (!$dryRun && $markedCount > 0) {
            $this->entityManager->flush();
        }

        $io->success(sprintf('%d import(s) marked as expired', $markedCount));

        // Delete old imports if requested
        if ($deleteOld) {
            $io->section('Deleting old imports');

            if ($dryRun) {
                $io->note('Dry run - no deletions will be performed');
            }

            $deletedCount = 0;
            if (!$dryRun) {
                $deletedCount = $this->importRepository->deleteExpired();
            }

            $io->success(sprintf('%d old import(s) deleted', $deletedCount));
        }

        if ($dryRun) {
            $io->warning('Dry run mode - no changes were made');
        }

        return Command::SUCCESS;
    }
}
