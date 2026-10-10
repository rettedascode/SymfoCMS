<?php

namespace App\Theme;

use App\Service\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Finds the themes in the themes/ folder and knows which one is active.
 *
 * A theme is a folder with a theme.json. Folders without a readable
 * manifest are ignored, so a broken theme can never take the website down.
 */
final class ThemeRegistry implements ResetInterface
{
    public const DEFAULT = 'default';
    private const ACTIVE_SETTING = 'theme.active';

    /** @var array<string, Theme>|null */
    private ?array $themes = null;

    public function __construct(
        #[Autowire(param: 'app.themes_dir')]
        private readonly string $themesDir,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return array<string, Theme> theme id => theme, the default theme first
     */
    public function all(): array
    {
        if (null !== $this->themes) {
            return $this->themes;
        }

        $themes = [];
        foreach (glob($this->themesDir.'/*/theme.json') ?: [] as $manifest) {
            $theme = $this->read(\dirname($manifest));
            if (null !== $theme) {
                $themes[$theme->id] = $theme;
            }
        }

        // A theme whose parent is missing cannot work.
        $themes = array_filter($themes, static fn (Theme $theme): bool => null === $theme->parent || isset($themes[$theme->parent]));
        uasort($themes, static fn (Theme $a, Theme $b): int => [self::DEFAULT !== $a->id, $a->name] <=> [self::DEFAULT !== $b->id, $b->name]);

        return $this->themes = $themes;
    }

    public function get(string $id): ?Theme
    {
        return $this->all()[$id] ?? null;
    }

    public function active(): Theme
    {
        $themes = $this->all();
        $chosen = $this->settings->get(self::ACTIVE_SETTING);

        return $themes[\is_string($chosen) ? $chosen : self::DEFAULT]
            ?? $themes[self::DEFAULT]
            ?? throw new \LogicException(\sprintf('The default theme is missing in "%s".', $this->themesDir));
    }

    public function activate(Theme $theme): void
    {
        $this->settings->set(self::ACTIVE_SETTING, self::DEFAULT === $theme->id ? null : $theme->id);
    }

    /**
     * The theme followed by the themes it is built on, e.g. [editorial, default].
     *
     * @return list<Theme>
     */
    public function lineage(Theme $theme): array
    {
        $lineage = [];
        for ($current = $theme; null !== $current && !isset($lineage[$current->id]); $current = null !== $current->parent ? $this->get($current->parent) : null) {
            $lineage[$current->id] = $current;
        }

        return array_values($lineage);
    }

    public function reset(): void
    {
        $this->themes = null;
    }

    private function read(string $path): ?Theme
    {
        $id = basename($path);
        $json = @file_get_contents($path.'/theme.json');
        $data = false !== $json ? json_decode($json, true) : null;

        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) || !\is_array($data) || !\is_string($data['name'] ?? null) || '' === trim($data['name'])) {
            return null;
        }

        $parent = \is_string($data['parent'] ?? null) && $data['parent'] !== $id ? $data['parent'] : null;
        $settings = \is_array($data['settings'] ?? null) ? $data['settings'] : [];

        $stylesheets = [];
        foreach (\is_array($data['stylesheets'] ?? null) ? $data['stylesheets'] : [] as $file) {
            // Only plain file names below the assets folder that really exist.
            if (\is_string($file) && 1 === preg_match('#^[\w-]+(?:/[\w-]+)*\.css$#', $file) && is_file($path.'/assets/'.$file)) {
                $stylesheets[] = $file;
            }
        }

        return new Theme(
            $id,
            $path,
            trim($data['name']),
            \is_string($data['description'] ?? null) ? $data['description'] : '',
            \is_string($data['author'] ?? null) ? $data['author'] : '',
            \is_string($data['version'] ?? null) ? $data['version'] : '',
            $parent,
            $stylesheets,
            $this->definitions($settings['colors'] ?? null, static fn (string $default): bool => 1 === preg_match('/^#[0-9a-f]{6}$/i', $default)),
            $this->definitions($settings['fonts'] ?? null, Fonts::has(...)),
        );
    }

    /**
     * @param \Closure(string): bool $isValidDefault
     *
     * @return array<string, array{label: string, variable: string, default: string}>
     */
    private function definitions(mixed $entries, \Closure $isValidDefault): array
    {
        $definitions = [];
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            $name = \is_array($entry) ? ($entry['name'] ?? null) : null;
            $variable = \is_array($entry) ? ($entry['variable'] ?? null) : null;
            $default = \is_array($entry) ? ($entry['default'] ?? null) : null;

            if (!\is_string($name) || 1 !== preg_match('/^[a-z][a-z0-9_]{0,29}$/', $name)
                || !\is_string($variable) || 1 !== preg_match('/^--[a-z][a-z0-9-]{0,49}$/', $variable)
                || !\is_string($default) || !$isValidDefault($default)) {
                continue;
            }

            $definitions[$name] = [
                'label' => \is_string($entry['label'] ?? null) ? $entry['label'] : $name,
                'variable' => $variable,
                'default' => strtolower($default),
            ];
        }

        return $definitions;
    }
}
