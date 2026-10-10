<?php

namespace App\Twig;

use App\Entity\Content;
use App\Service\StructuredData;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig functions for search engines and social networks.
 */
final class SeoExtension
{
    public function __construct(private readonly StructuredData $structuredData)
    {
    }

    /**
     * JSON-LD script elements describing a page or post.
     *
     * @param array<array-key, mixed> $blocks
     */
    #[AsTwigFunction('structured_data', isSafe: ['html'])]
    public function structuredData(Content $content, array $blocks): string
    {
        $html = '';
        foreach ($this->structuredData->forContent($content, $blocks) as $object) {
            // The HEX flags keep "</script>" and similar from ever ending the element early.
            $json = json_encode($object, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            $html .= '<script type="application/ld+json">'.$json.'</script>'."\n";
        }

        return $html;
    }
}
