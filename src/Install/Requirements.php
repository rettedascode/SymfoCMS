<?php

namespace App\Install;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks whether the server can run the website.
 */
final class Requirements
{
    private const DRIVERS = ['pdo_sqlite', 'pdo_mysql', 'pdo_pgsql'];

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'app.media_dir')]
        private readonly string $mediaDir,
    ) {
    }

    /**
     * @return list<array{label: string, parameters: array<string, string>, ok: bool, required: bool}>
     *                                                                                                 "label" is a translation key
     */
    public function check(): array
    {
        $checks = [
            $this->result('install.check.php', version_compare(\PHP_VERSION, '8.3.0', '>='), true, ['%version%' => \PHP_VERSION]),
        ];

        foreach (['ctype', 'iconv', 'mbstring', 'pdo', 'gd', 'fileinfo', 'intl'] as $extension) {
            $checks[] = $this->result('install.check.extension', \extension_loaded($extension), 'intl' !== $extension, ['%name%' => $extension]);
        }

        $checks[] = $this->result('install.check.driver', [] !== $this->availableDrivers(), true, ['%drivers%' => implode(', ', self::DRIVERS)]);
        $checks[] = $this->result('install.check.writable', $this->isWritable($this->projectDir.'/var'), true, ['%path%' => 'var/']);
        $checks[] = $this->result('install.check.writable', $this->isWritable($this->mediaDir), true, ['%path%' => 'public/uploads/']);
        // Only needed for exporting and importing a website.
        $checks[] = $this->result('install.check.extension', \extension_loaded('zip'), false, ['%name%' => 'zip']);

        return $checks;
    }

    public function isSatisfied(): bool
    {
        foreach ($this->check() as $check) {
            if ($check['required'] && !$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string> of "sqlite", "mysql", "pgsql"
     */
    public function availableDrivers(): array
    {
        return array_values(array_map(
            static fn (string $extension): string => substr($extension, 4),
            array_filter(self::DRIVERS, \extension_loaded(...)),
        ));
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array{label: string, parameters: array<string, string>, ok: bool, required: bool}
     */
    private function result(string $label, bool $ok, bool $required, array $parameters = []): array
    {
        return ['label' => $label, 'parameters' => $parameters, 'ok' => $ok, 'required' => $required];
    }

    /**
     * A folder counts as writable when it, or the closest existing folder above it, can be written to.
     */
    private function isWritable(string $directory): bool
    {
        while (!is_dir($directory) && \dirname($directory) !== $directory) {
            $directory = \dirname($directory);
        }

        return is_writable($directory);
    }
}
