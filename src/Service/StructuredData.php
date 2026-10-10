<?php

namespace App\Service;

use App\Block\BlockRegistry;
use App\Block\Field;
use App\Entity\Content;
use App\Entity\Media;
use App\Entity\Post;
use App\Twig\CmsExtension;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the JSON-LD descriptions that help search engines understand a page:
 * the article behind a blog post, questions and answers, and the breadcrumb trail.
 */
final class StructuredData
{
    public function __construct(
        private readonly SiteUrls $siteUrls,
        private readonly BlockRegistry $registry,
        private readonly CmsExtension $cms,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        private readonly SiteInfo $site,
    ) {
    }

    /**
     * @param array<array-key, mixed> $blocks the blocks that are actually shown
     *
     * @return list<array<string, mixed>> one schema.org object per entry
     */
    public function forContent(Content $content, array $blocks): array
    {
        $data = [];
        $url = $this->siteUrls->content($content, absolute: true);
        $home = $this->siteUrls->generate('app_home', [], $content->getLocale(), absolute: true);

        if ($content instanceof Post) {
            $image = $content->getShareImage() ?? $content->getCoverImage();
            $data[] = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => mb_substr($content->getTitle(), 0, 110),
                'description' => $content->getMetaDescription() ?? $content->getExcerpt(),
                'image' => null !== $image ? [$this->absoluteMediaUrl($image)] : null,
                'datePublished' => $content->getPublishedAt()?->format(\DATE_ATOM),
                'dateModified' => $content->getUpdatedAt()->format(\DATE_ATOM),
                'author' => null !== $content->getAuthor() ? ['@type' => 'Person', 'name' => $content->getAuthor()->getName()] : null,
                'publisher' => ['@type' => 'Organization', 'name' => $this->site->name()],
                'mainEntityOfPage' => $url,
                'inLanguage' => $content->getLocale(),
            ]);
        }

        $questions = $this->questions($blocks);
        if ([] !== $questions) {
            $data[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $questions];
        }

        // Breadcrumbs only make sense below the homepage.
        if ($url !== $home) {
            $trail = [[$this->site->name(), $home]];
            if ($content instanceof Post) {
                $trail[] = [$this->translator->trans('site.blog', [], null, $content->getLocale()), $this->siteUrls->generate('app_blog', [], $content->getLocale(), absolute: true)];
            }
            $trail[] = [$content->getTitle(), $url];

            $items = [];
            foreach ($trail as $position => [$name, $link]) {
                $items[] = ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $name, 'item' => $link];
            }
            $data[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }

        return $data;
    }

    /**
     * Questions and answers of every "item list" block that has a question and an answer, e.g. the accordion.
     *
     * @param array<array-key, mixed> $blocks
     *
     * @return list<array<string, mixed>>
     */
    private function questions(array $blocks): array
    {
        $questions = [];

        foreach ($blocks as $block) {
            if (!\is_array($block) || !\is_string($block['type'] ?? null) || !$this->registry->has($block['type'])) {
                continue;
            }

            foreach ($this->registry->get($block['type'])->getFields() as $field) {
                $value = $block['data'][$field->name] ?? null;
                if (!\is_array($value)) {
                    continue;
                }

                if (Field::BLOCKS === $field->type) {
                    $questions = [...$questions, ...$this->questions($value)];
                } elseif (Field::ITEMS === $field->type) {
                    foreach ($value as $item) {
                        $question = \is_array($item) && \is_string($item['question'] ?? null) ? trim($item['question']) : '';
                        $answer = \is_array($item) && \is_string($item['answer'] ?? null) ? trim($item['answer']) : '';
                        if ('' !== $question && '' !== $answer) {
                            $questions[] = [
                                '@type' => 'Question',
                                'name' => $question,
                                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
                            ];
                        }
                    }
                }
            }
        }

        return $questions;
    }

    private function absoluteMediaUrl(Media $media): string
    {
        $path = $this->cms->mediaUrl($media, 'large');
        $request = $this->requestStack->getMainRequest();

        return null !== $request && str_starts_with($path, '/') ? $request->getSchemeAndHttpHost().$path : $path;
    }
}
