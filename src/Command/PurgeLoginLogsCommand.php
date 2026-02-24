<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LoginLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-login-logs',
    description: 'Purge login logs older than 90 days',
)]
class PurgeLoginLogsCommand extends Command
{
    public function __construct(
        private readonly LoginLogRepository $loginLogRepository,
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
        $before = new \DateTimeImmutable('-90 days');

        if ($input->getOption('dry-run')) {
            $count = $this->loginLogRepository->createQueryBuilder('ll')
                ->select('COUNT(ll.id)')
                ->where('ll.createdAt < :before')
                ->setParameter('before', $before)
                ->getQuery()
                ->getSingleScalarResult();

            $io->info(sprintf('%d login log(s) would be purged (older than %s).', $count, $before->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $count = $this->loginLogRepository->purgeOlderThan($before);
        $io->success(sprintf('%d login log(s) purged (older than %s).', $count, $before->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
