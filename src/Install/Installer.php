<?php

namespace App\Install;

use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\MenuItemType;
use App\Enum\MenuLocation;
use App\Enum\UserRole;
use App\Service\ContentLocales;
use App\Service\ContentManager;
use App\Service\SiteInfo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The last step of the setup wizard: creates the tables, the first
 * administrator and a homepage, then closes the installer.
 */
final class Installer
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly InstallState $state,
        private readonly SiteInfo $site,
        private readonly ContentLocales $locales,
        private readonly ContentManager $contentManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string $locale language of the website and of the administrator's interface
     *
     * @throws \RuntimeException when the tables cannot be created or the website already has a user
     */
    public function install(string $siteName, string $locale, string $adminName, string $email, string $password): User
    {
        $this->migrate();

        if ($this->state->hasUsers()) {
            throw new \RuntimeException('This website is already set up.');
        }

        $admin = (new User())
            ->setName($adminName)
            ->setEmail($email)
            ->setRole(UserRole::Admin)
            ->setActive(true)
            ->setLocale($locale);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $this->site->save($siteName, '');
        $this->locales->save([$locale]);
        $this->createHomepage($siteName, $locale);

        $this->state->markInstalled();

        return $admin;
    }

    /**
     * Runs the database migrations, exactly like "php bin/console doctrine:migrations:migrate".
     */
    private function migrate(): void
    {
        // Creating all tables can take a moment on slow shared hosting.
        @set_time_limit(300);

        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
            '--no-ansi' => true,
        ]), $output);

        if (0 !== $exitCode) {
            throw new \RuntimeException(trim($output->fetch()));
        }

        // MySQL ends a transaction on its own whenever a table is created, which leaves the connection
        // believing it is still inside the transactions of the migrations. A fresh connection starts clean.
        $this->entityManager->getConnection()->close();
    }

    /**
     * A first page, so the new website does not greet its owner with nothing.
     */
    private function createHomepage(string $siteName, string $locale): void
    {
        if ($this->entityManager->getRepository(Page::class)->count([]) > 0) {
            return;
        }

        $text = fn (string $key): string => $this->translator->trans($key, ['%site%' => $siteName], null, $locale);

        $page = (new Page())
            ->setLocale($locale)
            ->setTitle($text('install.homepage.title'))
            ->setSlug('home')
            ->setHomepage(true)
            ->setStatus(ContentStatus::Published);

        $this->contentManager->save($page, [
            ['type' => 'text', 'data' => ['content' => '<p>'.htmlspecialchars($text('install.homepage.text'), \ENT_QUOTES).'</p>']],
        ]);

        $this->entityManager->persist(
            (new MenuItem())
                ->setLocation(MenuLocation::Main)
                ->setLocale($locale)
                ->setType(MenuItemType::Page)
                ->setLabel($text('install.homepage.menu'))
                ->setPage($page)
        );
        $this->entityManager->flush();
    }
}
