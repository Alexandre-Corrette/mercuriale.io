<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\RefreshTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-expired-tokens',
    description: 'Purge expired and revoked refresh tokens',
)]
class PurgeExpiredTokensCommand extends Command
{
    public function __construct(
        private readonly RefreshTokenRepository $refreshTokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show how many tokens would be purged without deleting them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run')) {
            $expired = $this->refreshTokenRepository->createQueryBuilder('rt')
                ->select('COUNT(rt.id)')
                ->where('rt.valid < :now')
                ->orWhere('rt.revokedAt IS NOT NULL')
                ->setParameter('now', new \DateTime())
                ->getQuery()
                ->getSingleScalarResult();

            $io->info(sprintf('%d token(s) would be purged.', $expired));

            return Command::SUCCESS;
        }

        $count = $this->refreshTokenRepository->purgeExpiredAndRevoked();
        $io->success(sprintf('%d expired/revoked refresh token(s) purged.', $count));

        return Command::SUCCESS;
    }
}
