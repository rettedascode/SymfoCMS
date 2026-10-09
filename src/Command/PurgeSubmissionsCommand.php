<?php

namespace App\Command;

use App\Service\FormSubmitter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Old submissions are also removed whenever a form is sent or the forms
 * section is opened, so this command is optional. It is useful in a cron job
 * on sites whose forms are rarely used.
 */
#[AsCommand(name: 'app:forms:purge', description: 'Deletes form submissions that are older than the retention period set in the admin area')]
final class PurgeSubmissionsCommand extends Command
{
    public function __construct(private readonly FormSubmitter $submitter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->submitter->purgeExpired();
        (new SymfonyStyle($input, $output))->success(\sprintf('Deleted %d submission(s).', $deleted));

        return Command::SUCCESS;
    }
}
