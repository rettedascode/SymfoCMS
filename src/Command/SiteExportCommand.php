<?php

namespace App\Command;

use App\Transfer\SiteExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The same export as in the admin area, for scheduled backups and for
 * websites that are too large to download through the browser.
 */
#[AsCommand(name: 'app:site:export', description: 'Writes content, menus, settings and images of the website into a ZIP file')]
final class SiteExportCommand extends Command
{
    public function __construct(private readonly SiteExporter $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path of the ZIP file to write');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');

        $counts = $this->exporter->export($file);

        $io->success(\sprintf('Exported to %s: %s.', $file, implode(', ', array_map(
            static fn (string $what, int $count): string => $count.' '.str_replace('_', ' ', $what),
            array_keys($counts),
            $counts,
        ))));

        return Command::SUCCESS;
    }
}
