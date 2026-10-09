<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;
use App\Service\CodeHighlighter;

/**
 * Shows source code exactly as typed, with syntax highlighting.
 */
final class CodeBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'code';
    }

    public function getIcon(): string
    {
        return '</>';
    }

    public function getPosition(): int
    {
        return 95;
    }

    public function getFields(): array
    {
        return [
            Field::code('code', 'block.code.code')->required(),
            Field::select('language', 'block.code.language', CodeHighlighter::LANGUAGES, 'plain'),
        ];
    }
}
