<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-audit-logs',
    description: 'Purge audit logs older than 365 days',
)]
class PurgeAuditLogsCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show how many logs would be purged without deleting them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $before = new \DateTimeImmutable('-365 days');

        if ($input->getOption('dry-run')) {
            $count = $this->auditLogRepository->createQueryBuilder('al')
                ->select('COUNT(al.id)')
                ->where('al.createdAt < :before')
                ->setParameter('before', $before)
                ->getQuery()
                ->getSingleScalarResult();

            $io->info(sprintf('%d audit log(s) would be purged (older than %s).', $count, $before->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $count = $this->auditLogRepository->purgeOlderThan($before);
        $io->success(sprintf('%d audit log(s) purged (older than %s).', $count, $before->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
