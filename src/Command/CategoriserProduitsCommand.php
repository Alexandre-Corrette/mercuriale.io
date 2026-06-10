<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CategorieProduit;
use App\Entity\Produit;
use App\Service\Categorisation\CategoryGuesserInterface;
use App\Service\Categorisation\ClaudeCategoryGuesser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:produits:categoriser',
    description: 'Attribue une catégorie aux produits sans catégorie (mots-clés, puis fallback Claude optionnel).',
)]
final class CategoriserProduitsCommand extends Command
{
    /** Nombre de produits par appel Claude (1 appel classe tout le lot). */
    private const CLAUDE_BATCH_SIZE = 40;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryGuesserInterface $keywordGuesser,
        private readonly ClaudeCategoryGuesser $claudeGuesser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait attribué sans rien écrire.')
            ->addOption('with-claude', null, InputOption::VALUE_NONE, 'Envoie les produits non résolus par les mots-clés à Claude (appels API, payant).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $withClaude = (bool) $input->getOption('with-claude');

        $categoriesParCode = [];
        foreach ($this->entityManager->getRepository(CategorieProduit::class)->findAll() as $cat) {
            $categoriesParCode[$cat->getCode()] = $cat;
        }
        if ($categoriesParCode === []) {
            $io->error('Aucune catégorie en base. Lancez d\'abord la migration de seed (taxonomie).');

            return Command::FAILURE;
        }

        $produits = $this->entityManager->getRepository(Produit::class)->findBy(['categorie' => null]);
        if ($produits === []) {
            $io->success('Aucun produit sans catégorie. Rien à faire.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Catégorisation de %d produit(s)%s', count($produits), $dryRun ? ' (DRY-RUN)' : ''));

        /** @var array<string, int> $compteParCategorie */
        $compteParCategorie = [];
        $parKeyword = 0;
        $parClaude = 0;

        // --- Phase 1 : règles mots-clés (gratuit) ---
        /** @var list<Produit> $nonResolus */
        $nonResolus = [];
        foreach ($produits as $produit) {
            $code = $this->keywordGuesser->guess((string) $produit->getNom());
            if ($code !== null && isset($categoriesParCode[$code])) {
                if (!$dryRun) {
                    $produit->setCategorie($categoriesParCode[$code]);
                }
                $compteParCategorie[$code] = ($compteParCategorie[$code] ?? 0) + 1;
                ++$parKeyword;
            } else {
                $nonResolus[] = $produit;
            }
        }
        $io->writeln(sprintf('Mots-clés : <info>%d</info> attribué(s), %d non résolu(s).', $parKeyword, count($nonResolus)));

        // --- Phase 2 : fallback Claude (optionnel) ---
        if ($withClaude && $nonResolus !== []) {
            $batches = array_chunk($nonResolus, self::CLAUDE_BATCH_SIZE);
            $io->writeln(sprintf('Claude : %d produit(s) en %d appel(s)...', count($nonResolus), count($batches)));
            $io->progressStart(count($batches));

            $encoreNonResolus = [];
            foreach ($batches as $batch) {
                $designations = array_map(static fn (Produit $p): string => (string) $p->getNom(), $batch);
                $codes = $this->claudeGuesser->guessBatch(array_values($designations));

                foreach ($batch as $i => $produit) {
                    $code = $codes[$i] ?? null;
                    if ($code !== null && isset($categoriesParCode[$code])) {
                        if (!$dryRun) {
                            $produit->setCategorie($categoriesParCode[$code]);
                        }
                        $compteParCategorie[$code] = ($compteParCategorie[$code] ?? 0) + 1;
                        ++$parClaude;
                    } else {
                        $encoreNonResolus[] = $produit;
                    }
                }
                $io->progressAdvance();
            }
            $io->progressFinish();
            $nonResolus = $encoreNonResolus;
            $io->writeln(sprintf('Claude : <info>%d</info> attribué(s), %d encore non résolu(s).', $parClaude, count($nonResolus)));
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        // --- Récap ---
        arsort($compteParCategorie);
        $rows = [];
        foreach ($compteParCategorie as $code => $count) {
            $rows[] = [$categoriesParCode[$code]->getNom(), $code, $count];
        }
        $io->table(['Catégorie', 'Code', 'Produits'], $rows);

        $io->writeln(sprintf(
            'Total attribués : <info>%d</info> / %d  (mots-clés: %d, Claude: %d) — non résolus: <comment>%d</comment>',
            $parKeyword + $parClaude,
            count($produits),
            $parKeyword,
            $parClaude,
            count($nonResolus),
        ));

        if ($nonResolus !== [] && $output->isVerbose()) {
            $io->section('Non résolus (échantillon)');
            $io->listing(array_slice(array_map(static fn (Produit $p): string => (string) $p->getNom(), $nonResolus), 0, 40));
        }

        if ($dryRun) {
            $io->note('DRY-RUN : aucune modification écrite.');
        } else {
            $io->success('Catégories attribuées et enregistrées.');
        }

        return Command::SUCCESS;
    }
}
