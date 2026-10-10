<?php

namespace App\Install;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Answers "has this website been set up?".
 *
 * A website is installed as soon as it has a user. That is remembered in a
 * small file so the question costs no database query afterwards, and so the
 * installer stays closed for good - it cannot be reopened through the web.
 */
final class InstallState
{
    private ?bool $installed = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'app.install_lock')]
        private readonly string $lockFile,
        #[Autowire(env: 'bool:APP_INSTALLER')]
        private readonly bool $installerEnabled,
    ) {
    }

    /**
     * False only when the installer may run: it is enabled and the website has no user yet.
     */
    public function isInstalled(): bool
    {
        if (null !== $this->installed) {
            return $this->installed;
        }

        if (!$this->installerEnabled || is_file($this->lockFile)) {
            return $this->installed = true;
        }

        // Websites set up on the command line, or before the installer existed, are recognised by their users.
        if ($this->hasUsers()) {
            $this->markInstalled();

            return true;
        }

        return $this->installed = false;
    }

    public function markInstalled(): void
    {
        $this->installed = true;

        try {
            (new Filesystem())->dumpFile($this->lockFile, "This website was set up. Deleting this file does not reopen the installer while a user exists.\n");
        } catch (\Throwable) {
            // Without the file the users are counted on every request, which works just as well.
        }
    }

    public function hasUsers(): bool
    {
        try {
            return $this->entityManager->getRepository(User::class)->count([]) > 0;
        } catch (\Throwable) {
            // No connection or no tables yet.
            return false;
        }
    }
}
