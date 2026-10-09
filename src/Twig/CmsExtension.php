<?php

namespace App\Twig;

use App\Block\BlockRenderer;
use App\Entity\Media;
use App\Enum\MenuLocation;
use App\Repository\MediaRepository;
use App\Service\MenuBuilder;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig functions used by the public theme and the block templates.
 */
final class CmsExtension
{
    /** @var array<int, Media|null> */
    private array $mediaCache = [];

    public function __construct(
        private readonly BlockRenderer $blockRenderer,
        private readonly MediaRepository $mediaRepository,
        private readonly Packages $packages,
        #[Autowire(param: 'app.media_public_path')]
        private readonly string $mediaPublicPath,
        private readonly LocaleAwareInterface $localeAware,
        private readonly MenuBuilder $menuBuilder,
    ) {
    }

    /**
     * @param array<array-key, mixed>|null $blocks
     */
    #[AsTwigFunction('render_blocks', isSafe: ['html'])]
    public function renderBlocks(?array $blocks): string
    {
        return $this->blockRenderer->render($blocks ?? []);
    }

    /**
     * Looks up an image of the media library by id. Returns null when it was deleted.
     */
    #[AsTwigFunction('cms_media')]
    public function media(mixed $id): ?Media
    {
        if (!is_numeric($id)) {
            return null;
        }

        $id = (int) $id;
        if (!\array_key_exists($id, $this->mediaCache)) {
            $this->mediaCache[$id] = $this->mediaRepository->find($id);
        }

        return $this->mediaCache[$id];
    }

    /**
     * Public URL of an image. Variants: thumb, medium, large or original.
     */
    #[AsTwigFunction('media_url')]
    public function mediaUrl(Media $media, string $variant = 'original'): string
    {
        return $this->packages->getUrl($this->mediaPublicPath.'/'.$media->getFile($variant));
    }

    /**
     * Value for the srcset attribute listing every available size.
     */
    #[AsTwigFunction('media_srcset')]
    public function mediaSrcset(Media $media): string
    {
        $candidates = [];
        foreach ($media->getVariants() as $variant) {
            $candidates[$variant['width']] = $this->packages->getUrl($this->mediaPublicPath.'/'.$variant['file']).' '.$variant['width'].'w';
        }
        $candidates[$media->getWidth()] ??= $this->mediaUrl($media).' '.$media->getWidth().'w';
        ksort($candidates);

        return implode(', ', $candidates);
    }

    /**
     * Items of a menu ("main" or "footer") with resolved links.
     *
     * @return list<array{label: string, url: string, current: bool, children: list<array{label: string, url: string, current: bool, children: array{}}>}>
     */
    #[AsTwigFunction('cms_menu')]
    public function menu(string $location, ?string $locale = null): array
    {
        return $this->menuBuilder->build(MenuLocation::from($location), $locale);
    }

    /**
     * Formats a date the way readers of the current language expect it.
     */
    #[AsTwigFilter('local_date')]
    public function localDate(?\DateTimeInterface $date, bool $withTime = false): string
    {
        if (null === $date) {
            return '';
        }

        if (!class_exists(\IntlDateFormatter::class)) {
            return $date->format($withTime ? 'Y-m-d H:i' : 'Y-m-d');
        }

        $formatter = new \IntlDateFormatter(
            $this->localeAware->getLocale(),
            $withTime ? \IntlDateFormatter::MEDIUM : \IntlDateFormatter::LONG,
            $withTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE,
            $date->getTimezone(),
        );

        return (string) $formatter->format($date);
    }
}
