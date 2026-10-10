<?php

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Service\ContentLocales;
use App\Transfer\WordPressImporter;
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
 * The WordPress import of the admin area for large export files: no upload
 * limit and no time limit of the web server.
 */
#[AsCommand(name: 'app:wordpress:import', description: 'Imports posts, pages, categories and images from a WordPress export file (WXR)')]
final class WordPressImportCommand extends Command
{
    public function __construct(
        private readonly WordPressImporter $importer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentLocales $locales,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the XML file exported by WordPress')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Language of the imported content (default: the default language of the website)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locale = (string) ($input->getOption('locale') ?? $this->locales->default());

        if (!$this->locales->isEnabled($locale)) {
            $io->error(\sprintf('"%s" is not a language of this website. Available: %s.', $locale, implode(', ', $this->locales->all())));

            return Command::FAILURE;
        }

        // Imported posts belong to the first administrator.
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['role' => UserRole::Admin], ['id' => 'ASC']);

        try {
            $report = $this->importer->import((string) $input->getArgument('file'), $locale, $admin);
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
