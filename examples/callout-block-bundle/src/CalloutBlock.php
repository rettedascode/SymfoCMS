<?php

namespace Acme\CalloutBlock;

use App\Block\AbstractBlockType;
use App\Block\Field;

/**
 * A highlighted box with a title and a short text.
 */
final class CalloutBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'acme_callout';
    }

    public function getLabel(): string
    {
        return 'acme_callout.label';
    }

    public function getIcon(): string
    {
        return '!';
    }

    public function getFields(): array
    {
        return [
            Field::text('title', 'acme_callout.title')->required(),
            Field::textarea('text', 'acme_callout.text'),
            Field::select('tone', 'acme_callout.tone', [
                'info' => 'acme_callout.tone_info',
                'warning' => 'acme_callout.tone_warning',
            ], 'info'),
        ];
    }

    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        if (mb_strlen((string) $data['title']) > 80) {
            $errors['title'] = 'acme_callout.title_too_long';
        }

        return $errors;
    }

    public function getTemplate(): string
    {
        return '@AcmeCalloutBlock/blocks/callout.html.twig';
    }
}
