<?php

namespace App\Tests\Unit;

use App\Repository\SettingRepository;
use App\Service\Settings;
use App\Theme\ThemeRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ThemeRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/symfocms-themes-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testReadsManifestsAndIgnoresBrokenThemes(): void
    {
        $this->theme('default', ['name' => 'Default', 'stylesheets' => ['theme.css', '../../../.env', 'missing.css'], 'settings' => [
            'colors' => [
                ['name' => 'accent', 'variable' => '--color-accent', 'default' => '#2F55D4', 'label' => 'Accent'],
                ['name' => 'evil', 'variable' => '--x;}body{display:none', 'default' => '#000000'],
                ['name' => 'named', 'variable' => '--color-named', 'default' => 'red'],
            ],
            'fonts' => [
                ['name' => 'body', 'variable' => '--font-body', 'default' => 'inter'],
                ['name' => 'unknown', 'variable' => '--font-x', 'default' => 'comic-sans'],
            ],
        ]], ['theme.css']);
        $this->theme('child', ['name' => 'Child', 'parent' => 'default', 'stylesheets' => ['theme.css']], ['theme.css'], templates: true);
        $this->theme('orphan', ['name' => 'Orphan', 'parent' => 'gone']);
        $this->theme('nameless', ['description' => 'No name']);
        $this->theme('Bad Folder', ['name' => 'Bad folder name']);
        (new Filesystem())->dumpFile($this->dir.'/broken/theme.json', '{not json');

        $registry = new ThemeRegistry($this->dir, $this->settings());
        $themes = $registry->all();

        self::assertSame(['default', 'child'], array_keys($themes));
        self::assertSame(['theme.css'], $themes['default']->stylesheets, 'Only existing files inside the assets folder are used.');
        self::assertSame(['accent' => ['label' => 'Accent', 'variable' => '--color-accent', 'default' => '#2f55d4']], $themes['default']->colors);
        self::assertSame(['body'], array_keys($themes['default']->fonts));
        self::assertNull($themes['default']->templatePath());
        self::assertNotNull($themes['child']->templatePath());
        self::assertSame(['child', 'default'], array_map(static fn ($theme): string => $theme->id, $registry->lineage($themes['child'])));
        self::assertSame('default', $registry->active()->id);
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string>         $assets
     */
    private function theme(string $id, array $manifest, array $assets = [], bool $templates = false): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->dir.'/'.$id.'/theme.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
        foreach ($assets as $asset) {
            $filesystem->dumpFile($this->dir.'/'.$id.'/assets/'.$asset, '/* css */');
        }
        if ($templates) {
            $filesystem->mkdir($this->dir.'/'.$id.'/templates');
        }
    }

    private function settings(): Settings
    {
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('findAll')->willReturn([]);

        return new Settings($repository, $this->createStub(EntityManagerInterface::class));
    }
}
