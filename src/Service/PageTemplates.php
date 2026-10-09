<?php

namespace App\Service;

use App\Block\BlockNormalizer;
use App\Repository\PageTemplateRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Starting points offered when a new page is created: a few built-in ones
 * and those an admin saved from existing pages.
 *
 * A template is nothing but a list of blocks that is copied into the new
 * page. After that the page is edited freely and has no link to the template.
 */
final class PageTemplates
{
    public const BLANK = 'blank';
    private const BUILT_IN = ['landing', 'about', 'contact'];

    public function __construct(
        private readonly PageTemplateRepository $savedTemplates,
        private readonly TranslatorInterface $translator,
        private readonly BlockNormalizer $normalizer,
    ) {
    }

    /**
     * @return array<string, array{name: string, description: string}> template key => texts for the choice
     */
    public function choices(): array
    {
        $choices = [self::BLANK => ['name' => $this->translator->trans('template.blank.name'), 'description' => $this->translator->trans('template.blank.description')]];

        foreach (self::BUILT_IN as $key) {
            $choices[$key] = ['name' => $this->translator->trans('template.'.$key.'.name'), 'description' => $this->translator->trans('template.'.$key.'.description')];
        }

        foreach ($this->savedTemplates->findBy([], ['name' => 'ASC']) as $template) {
            $choices['saved-'.$template->getId()] = ['name' => $template->getName(), 'description' => $this->translator->trans('template.saved_description')];
        }

        return $choices;
    }

    /**
     * The blocks a new page starts with.
     *
     * @param string $locale language of the new page: the placeholder texts of built-in templates are written in it
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function blocks(string $key, string $locale): array
    {
        if (str_starts_with($key, 'saved-')) {
            $template = $this->savedTemplates->find((int) substr($key, 6));

            return null === $template ? [] : $this->normalizer->normalize($template->getBlocks());
        }

        $text = fn (string $id): string => $this->translator->trans('template.'.$key.'.'.$id, [], null, $locale);

        $blocks = match ($key) {
            'landing' => [
                ['type' => 'text', 'data' => ['content' => '<p>'.$text('intro').'</p>']],
                ['type' => 'button', 'data' => ['label' => $text('button'), 'url' => '#', 'style' => 'primary']],
                ['type' => 'heading', 'data' => ['text' => $text('benefits'), 'level' => '2']],
                ['type' => 'columns', 'data' => [
                    'left' => [
                        ['type' => 'heading', 'data' => ['text' => $text('benefit_one'), 'level' => '3']],
                        ['type' => 'text', 'data' => ['content' => '<p>'.$text('benefit_text').'</p>']],
                    ],
                    'right' => [
                        ['type' => 'heading', 'data' => ['text' => $text('benefit_two'), 'level' => '3']],
                        ['type' => 'text', 'data' => ['content' => '<p>'.$text('benefit_text').'</p>']],
                    ],
                ]],
                ['type' => 'quote', 'data' => ['text' => $text('quote'), 'author' => $text('quote_author')]],
            ],
            'about' => [
                ['type' => 'text', 'data' => ['content' => '<p>'.$text('intro').'</p>']],
                ['type' => 'image', 'data' => ['image' => null, 'alt' => '', 'caption' => '']],
                ['type' => 'heading', 'data' => ['text' => $text('story'), 'level' => '2']],
                ['type' => 'text', 'data' => ['content' => '<p>'.$text('story_text').'</p>']],
                ['type' => 'heading', 'data' => ['text' => $text('team'), 'level' => '2']],
                ['type' => 'text', 'data' => ['content' => '<p>'.$text('team_text').'</p>']],
            ],
            'contact' => [
                ['type' => 'text', 'data' => ['content' => '<p>'.$text('intro').'</p>']],
                ['type' => 'columns', 'data' => [
                    'left' => [
                        ['type' => 'heading', 'data' => ['text' => $text('address'), 'level' => '3']],
                        ['type' => 'text', 'data' => ['content' => '<p>'.$text('address_text').'</p>']],
                    ],
                    'right' => [
                        ['type' => 'heading', 'data' => ['text' => $text('hours'), 'level' => '3']],
                        ['type' => 'text', 'data' => ['content' => '<p>'.$text('hours_text').'</p>']],
                    ],
                ]],
            ],
            default => [],
        };

        // Run through the normalizer so a template can never produce content the editor would not.
        return $this->normalizer->normalize($blocks);
    }
}
