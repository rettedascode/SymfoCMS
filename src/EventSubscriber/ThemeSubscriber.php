<?php

namespace App\EventSubscriber;

use App\Theme\ThemeRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Twig\Loader\FilesystemLoader;

/**
 * Makes Twig look into the active theme first.
 *
 * A theme only contains the templates it changes: "site/page.html.twig" is
 * taken from the active theme, then from its parent, and finally from the
 * default theme and templates/, which are always registered.
 */
final class ThemeSubscriber
{
    /** @var list<string>|null the template folders configured for the application itself */
    private ?array $basePaths = null;

    private ?string $applied = null;

    public function __construct(
        private readonly ThemeRegistry $themes,
        #[Autowire(service: 'twig.loader.native_filesystem')]
        private readonly FilesystemLoader $loader,
    ) {
    }

    #[AsEventListener(priority: 64)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $theme = $this->themes->active();
        if ($this->applied === $theme->id) {
            return;
        }

        $this->basePaths ??= $this->loader->getPaths();

        $paths = [];
        foreach ($this->themes->lineage($theme) as $ancestor) {
            $path = $ancestor->templatePath();
            if (null !== $path && ThemeRegistry::DEFAULT !== $ancestor->id) {
                $paths[] = $path;
            }
        }

        $this->loader->setPaths([...$paths, ...$this->basePaths]);
        $this->applied = $theme->id;
    }
}
