<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;
use App\Service\VideoEmbed;

/**
 * A video from YouTube or Vimeo. Nothing is loaded from the video platform
 * until the visitor clicks "play" (see templates/blocks/video.html.twig).
 */
final class VideoBlock extends AbstractBlockType
{
    public function __construct(private readonly VideoEmbed $videoEmbed)
    {
    }

    public static function getType(): string
    {
        return 'video';
    }

    public function getIcon(): string
    {
        return '▶';
    }

    public function getPosition(): int
    {
        return 36;
    }

    public function getFields(): array
    {
        return [
            Field::url('url', 'block.video.url')->required()->placeholder('https://www.youtube.com/watch?v=…')->help('block.video.url_help'),
            Field::text('title', 'block.video.title')->help('block.video.title_help'),
            Field::image('poster', 'block.video.poster')->help('block.video.poster_help'),
        ];
    }

    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        if (!isset($errors['url']) && null === $this->videoEmbed->parse($data['url'] ?? null)) {
            $errors['url'] = 'block.video.url_invalid';
        }

        return $errors;
    }
}
