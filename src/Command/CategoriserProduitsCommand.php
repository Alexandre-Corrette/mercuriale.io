<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CategorieProduit;
use App\Entity\Produit;
use App\Service\Categorisation\CategoryGuesserInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:produits:categoriser',
    description: 'Attribue une catégorie aux produits sans catégorie via le CategoryGuesser (mots-clés).',
)]
final class CategoriserProduitsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryGuesserInterface $guesser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Affiche ce qui serait attribué sans rien écrire en base.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // Catégories indexées par code.
        $categoriesParCode = [];
        foreach ($this->entityManager->getRepository(CategorieProduit::class)->findAll() as $cat) {
            $categoriesParCode[$cat->getCode()] = $cat;
        }

        if ($categoriesParCode === []) {
            $io->error('Aucune catégorie en base. Lancez d\'abord la migration de seed (taxonomie).');

            return Command::FAILURE;
        }

        // Uniquement les produits non catégorisés (on n'écrase jamais un choix existant).
        $produits = $this->entityManager->getRepository(Produit::class)->findBy(['categorie' => null]);

        if ($produits === []) {
            $io->success('Aucun produit sans catégorie. Rien à faire.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Catégorisation de %d produit(s) sans catégorie%s', count($produits), $dryRun ? ' (DRY-RUN)' : ''));

        /** @var array<string, int> $compteParCategorie */
        $compteParCategorie = [];
        $nonResolus = [];
        $assignes = 0;

        foreach ($produits as $produit) {
            $code = $this->guesser->guess((string) $produit->getNom());

            if ($code === null || !isset($categoriesParCode[$code])) {
                $nonResolus[] = $produit->getNom();
                continue;
            }

            if (!$dryRun) {
                $produit->setCategorie($categoriesParCode[$code]);
            }

            $compteParCategorie[$code] = ($compteParCategorie[$code] ?? 0) + 1;
            ++$assignes;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        // Récap par catégorie.
        arsort($compteParCategorie);
        $rows = [];
        foreach ($compteParCategorie as $code => $count) {
            $rows[] = [$categoriesParCode[$code]->getNom(), $code, $count];
        }
        $io->table(['Catégorie', 'Code', 'Produits'], $rows);

        $io->writeln(sprintf('Attribués : <info>%d</info> / %d', $assignes, count($produits)));
        $io->writeln(sprintf('Non résolus (laissés sans catégorie) : <comment>%d</comment>', count($nonResolus)));

        if ($nonResolus !== [] && $output->isVerbose()) {
            $io->section('Désignations non résolues (échantillon)');
            $io->listing(array_slice(array_map(static fn ($n): string => (string) $n, $nonResolus), 0, 40));
        }

        if ($dryRun) {
            $io->note('DRY-RUN : aucune modification écrite. Relancez sans --dry-run pour appliquer.');
        } else {
            $io->success('Catégories attribuées et enregistrées.');
        }

        return Command::SUCCESS;
    }
}
