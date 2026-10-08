<?php

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:create-admin', description: 'Creates an admin user (or promotes and resets an existing one)')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Email address used to log in')
            ->addArgument('password', InputArgument::OPTIONAL, 'Password (asked interactively and hidden when omitted)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name', 'Admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email') ?? $io->ask('Email address');
        $password = $input->getArgument('password') ?? $io->askHidden('Password (at least 8 characters)');
        $name = (string) $input->getOption('name');

        if (!\is_string($email) || 0 !== \count($this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]))) {
            $io->error('Please provide a valid email address.');

            return Command::INVALID;
        }

        if (!\is_string($password) || mb_strlen($password) < 8) {
            $io->error('The password must be at least 8 characters long.');

            return Command::INVALID;
        }

        $user = $this->users->findOneBy(['email' => mb_strtolower(trim($email))]);
        $isNew = null === $user;
        $user ??= (new User())->setEmail($email)->setName($name);

        $user
            ->setRole(UserRole::Admin)
            ->setActive(true)
            ->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('%s admin "%s". You can now log in at /login.', $isNew ? 'Created' : 'Updated', $user->getEmail()));

        return Command::SUCCESS;
    }
}
