<?php

namespace App\Command;

use App\Service\SearchIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The index follows every change on its own, so this command is rarely
 * needed: run it after content was changed directly in the database.
 */
#[AsCommand(name: 'app:search:reindex', description: 'Rebuilds the search index from all pages, posts and images')]
final class ReindexSearchCommand extends Command
{
    public function __construct(private readonly SearchIndexer $indexer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SymfonyStyle($input, $output))->success(\sprintf('Indexed %d item(s).', $this->indexer->rebuild()));

        return Command::SUCCESS;
    }
}
