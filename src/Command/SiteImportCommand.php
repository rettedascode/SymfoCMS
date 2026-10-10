<?php

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Transfer\SiteImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The same import as in the admin area, without the upload limit of the web server.
 */
#[AsCommand(name: 'app:site:import', description: 'Imports a ZIP file written by app:site:export or the export in the admin area')]
final class SiteImportCommand extends Command
{
    public function __construct(
        private readonly SiteImporter $importer,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the ZIP file to import')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Delete the content of this website first and take over the settings of the export');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $replace = (bool) $input->getOption('replace');

        if ($replace && !$io->confirm('This deletes all pages, posts, menus and images of this website before importing. Continue?', !$input->isInteractive())) {
            return Command::SUCCESS;
        }

        // Posts of authors that do not exist here are given to the first administrator.
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['role' => UserRole::Admin], ['id' => 'ASC']);

        try {
            $report = $this->importer->import($file, $replace, $admin);
        } catch (\InvalidArgumentException $exception) {
            $io->error($this->translator->trans($exception->getMessage(), [], null, 'en'));

            return Command::FAILURE;
        }

        foreach ($report->issues() as $issue) {
            $io->writeln(\sprintf(' ! %s: %s', $issue['subject'], $this->translator->trans($issue['message'], $issue['parameters'], null, 'en')));
        }

        $io->success('Imported: '.implode(', ', array_map(
            static fn (string $what, int $count): string => $count.' '.str_replace('_', ' ', $what),
            array_keys($report->counts()),
            $report->counts(),
        )).'.');

        return Command::SUCCESS;
    }
}
