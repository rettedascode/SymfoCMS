<?php

namespace App\Twig;

use App\Service\ContentEditor;
use Twig\Attribute\AsTwigFunction;

/**
 * Hands the interface text of the drag-and-drop layer (assets/lib/dnd.js) to the browser.
 */
final class DragDropExtension
{
    public function __construct(private readonly ContentEditor $editor)
    {
    }

    /**
     * All translations starting with "dnd." as JSON for a <script type="application/json"> element.
     */
    #[AsTwigFunction('dnd_texts', isSafe: ['html'])]
    public function texts(): string
    {
        return json_encode($this->editor->translations('dnd.'), \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_UNICODE);
    }
}
