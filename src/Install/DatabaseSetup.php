<?php

namespace App\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The database step of the installer: builds the connection address from
 * the form, tries it out and writes it to .env.local.
 */
final class DatabaseSetup
{
    public const SQLITE = 'sqlite';
    public const MYSQL = 'mysql';
    public const POSTGRESQL = 'pgsql';

    private const SQLITE_URL = 'sqlite:///%kernel.project_dir%/var/data.db';

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(param: 'app.install_env_file')]
        private readonly string $envFile,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
    ) {
    }

    /**
     * True when the connection is set by the server (a real environment variable or a
     * compiled .env.local.php). The installer then uses it as it is and cannot change it.
     */
    public function isManagedByServer(): bool
    {
        if (is_file($this->projectDir.'/.env.local.php')) {
            return true;
        }

        $fromDotenv = explode(',', (string) ($_SERVER['SYMFONY_DOTENV_VARS'] ?? $_ENV['SYMFONY_DOTENV_VARS'] ?? ''));

        return !\in_array('DATABASE_URL', $fromDotenv, true);
    }

    public function canWriteConfiguration(): bool
    {
        if (is_file($this->envFile)) {
            return is_writable($this->envFile);
        }

        // The file does not exist yet: the closest existing folder above it decides.
        $directory = \dirname($this->envFile);
        while (!is_dir($directory) && \dirname($directory) !== $directory) {
            $directory = \dirname($directory);
        }

        return is_writable($directory);
    }

    /**
     * The database in use right now, without the password: "MySQL · shop @ localhost".
     */
    public function describeCurrent(): string
    {
        $params = $this->connection->getParams();
        $driver = (string) ($params['driver'] ?? '');

        if (str_contains($driver, 'sqlite')) {
            return 'SQLite · '.basename((string) ($params['path'] ?? 'memory'));
        }

        return \sprintf('%s · %s @ %s', str_contains($driver, 'pgsql') ? 'PostgreSQL' : 'MySQL / MariaDB', (string) ($params['dbname'] ?? ''), (string) ($params['host'] ?? 'localhost'));
    }

    /**
     * @return string|null what went wrong, or null when the current connection works
     */
    public function testCurrent(): ?string
    {
        try {
            $this->connection->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL());

            return null;
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * Builds the value of DATABASE_URL.
     *
     * @param array{driver: string, host?: string|null, port?: int|null, name?: string|null, user?: string|null, password?: string|null} $data
     */
    public function url(array $data): string
    {
        if (self::SQLITE === $data['driver']) {
            return self::SQLITE_URL;
        }

        $isPostgres = self::POSTGRESQL === $data['driver'];
        $host = trim((string) ($data['host'] ?? ''), '[] ') ?: 'localhost';

        return \sprintf(
            '%s://%s:%s@%s:%d/%s?charset=%s',
            $isPostgres ? 'postgresql' : 'mysql',
            rawurlencode((string) ($data['user'] ?? '')),
            rawurlencode((string) ($data['password'] ?? '')),
            // An IPv6 address is written in brackets.
            str_contains($host, ':') ? '['.$host.']' : $host,
            $data['port'] ?? ($isPostgres ? 5432 : 3306),
            rawurlencode((string) ($data['name'] ?? '')),
            $isPostgres ? 'utf8' : 'utf8mb4',
        );
    }

    /**
     * Opens a connection with the given address.
     *
     * @return string|null what went wrong, or null when the database answered
     */
    public function test(string $url): ?string
    {
        try {
            $url = str_replace('%kernel.project_dir%', $this->projectDir, $url);
            $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']))->parse($url);

            if (isset($params['path'])) {
                (new Filesystem())->mkdir(\dirname($params['path']));
            }

            $connection = DriverManager::getConnection($params);
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
            $connection->close();

            return null;
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * Stores the connection in .env.local, where it survives updates of the software.
     * A missing application secret is created on the way.
     */
    public function write(string $url): void
    {
        $values = ['DATABASE_URL' => $this->escape($url)];
        if ('' === $this->secret) {
            $values['APP_SECRET'] = bin2hex(random_bytes(16));
        }

        $lines = is_file($this->envFile) ? preg_split('/\R/', rtrim((string) file_get_contents($this->envFile))) ?: [] : [];
        // Earlier values of the same variables are replaced.
        $lines = array_filter($lines, static fn (string $line): bool => 1 !== preg_match('/^\s*(?:export\s+)?('.implode('|', array_keys($values)).')\s*=/', $line));

        foreach ($values as $name => $value) {
            $lines[] = \sprintf('%s="%s"', $name, $value);
        }

        (new Filesystem())->dumpFile($this->envFile, implode("\n", $lines)."\n");
    }

    /**
     * The lines an administrator has to add by hand when the file cannot be written.
     */
    public function configurationFor(string $url): string
    {
        return \sprintf('DATABASE_URL="%s"', $this->escape($url));
    }

    /**
     * Percent signs of encoded passwords would be read as container parameters, so they are doubled -
     * except in the %kernel.project_dir% placeholder of the SQLite address.
     */
    private function escape(string $url): string
    {
        return self::SQLITE_URL === $url ? $url : str_replace('%', '%%', $url);
    }
}
