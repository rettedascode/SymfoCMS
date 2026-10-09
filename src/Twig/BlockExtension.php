<?php

namespace App\Twig;

use App\Block\Type\MapBlock;
use App\Service\CodeHighlighter;
use App\Service\VideoEmbed;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig functions used by the templates of individual block types.
 */
final class BlockExtension
{
    public function __construct(
        private readonly VideoEmbed $videoEmbed,
        private readonly CodeHighlighter $codeHighlighter,
    ) {
    }

    /**
     * @return array{provider: string, name: string, embedUrl: string, watchUrl: string}|null
     */
    #[AsTwigFunction('video_embed')]
    public function videoEmbed(mixed $url): ?array
    {
        return $this->videoEmbed->parse($url);
    }

    /**
     * Source code as HTML with highlighted keywords, strings and comments. The code itself is escaped.
     */
    #[AsTwigFunction('highlight_code', isSafe: ['html'])]
    public function highlightCode(mixed $code, mixed $language): string
    {
        return $this->codeHighlighter->highlight(\is_string($code) ? $code : '', \is_string($language) ? $language : 'plain');
    }

    /**
     * Addresses for showing a place on OpenStreetMap.
     *
     * @return array{embedUrl: string, linkUrl: string}|null null when the location is incomplete
     */
    #[AsTwigFunction('osm_map')]
    public function osmMap(mixed $location, mixed $zoom): ?array
    {
        if (!\is_array($location) || !is_numeric($location['lat'] ?? null) || !is_numeric($location['lon'] ?? null)) {
            return null;
        }

        $lat = (float) $location['lat'];
        $lon = (float) $location['lon'];
        $span = MapBlock::ZOOM_SPAN[\is_string($zoom) ? $zoom : ''] ?? MapBlock::ZOOM_SPAN['street'];
        // A degree of longitude gets shorter towards the poles; widen the box to keep it roughly 16:9.
        $lonSpan = $span * 1.8 / max(0.2, cos(deg2rad($lat)));
        $level = match ($span) {
            MapBlock::ZOOM_SPAN['region'] => 10,
            MapBlock::ZOOM_SPAN['town'] => 13,
            default => 16,
        };

        $number = static fn (float $value): string => rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return [
            'embedUrl' => \sprintf(
                'https://www.openstreetmap.org/export/embed.html?bbox=%s%%2C%s%%2C%s%%2C%s&layer=mapnik&marker=%s%%2C%s',
                $number($lon - $lonSpan), $number($lat - $span), $number($lon + $lonSpan), $number($lat + $span), $number($lat), $number($lon),
            ),
            'linkUrl' => \sprintf('https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=%3$d/%1$s/%2$s', $number($lat), $number($lon), $level),
        ];
    }
}
