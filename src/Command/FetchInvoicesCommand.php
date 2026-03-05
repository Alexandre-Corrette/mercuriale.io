<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\FetchPendingInvoicesMessage;
use App\Repository\EtablissementRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:fetch-invoices',
    description: 'Fetch pending invoices from PDP (B2Brouter) for all e-invoicing enabled establishments',
)]
class FetchInvoicesCommand extends Command
{
    public function __construct(
        private readonly EtablissementRepository $etablissementRepo,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $etablissements = $this->etablissementRepo->findEInvoicingEnabled();

        if (\count($etablissements) === 0) {
            $io->info('Aucun établissement inscrit à la facturation électronique.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Dispatch du polling pour %d établissement(s)...', \count($etablissements)));

        foreach ($etablissements as $etablissement) {
            $this->messageBus->dispatch(new FetchPendingInvoicesMessage($etablissement->getId()));
            $io->text(sprintf('  → %s (PDP: %s)', $etablissement->getNom(), $etablissement->getPdpAccountId()));
        }

        $io->success('Messages dispatchés.');

        return Command::SUCCESS;
    }
}
