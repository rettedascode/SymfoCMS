<?php

namespace App\Api;

use App\Block\BlockRenderer;
use App\Entity\Category;
use App\Entity\Content;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\Post;
use App\Service\ContentManager;
use App\Service\ContentTranslator;
use App\Service\SiteUrls;
use App\Twig\CmsExtension;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Turns content into the arrays the read API answers with.
 * The shapes are described in App\Api\OpenApi - keep both in step.
 */
final class ApiSerializer
{
    public function __construct(
        private readonly SiteUrls $siteUrls,
        private readonly CmsExtension $cms,
        private readonly RequestStack $requestStack,
        private readonly ContentManager $contentManager,
        private readonly ContentTranslator $translator,
        private readonly BlockRenderer $renderer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function page(Page $page, bool $withContent = false): array
    {
        return ['id' => $page->getId(), 'type' => 'page', 'homepage' => $page->isHomepage()] + $this->content($page, $withContent);
    }

    /**
     * @return array<string, mixed>
     */
    public function post(Post $post, bool $withContent = false): array
    {
        return [
            'id' => $post->getId(),
            'type' => 'post',
            'excerpt' => $post->getExcerpt(),
            'published_at' => $post->getPublishedAt()?->format(\DATE_ATOM),
            'author' => null !== $post->getAuthor() ? ['name' => $post->getAuthor()->getName()] : null,
            'category' => null !== $post->getCategory() ? $this->category($post->getCategory()) : null,
            'cover_image' => null !== $post->getCoverImage() ? $this->media($post->getCoverImage()) : null,
        ] + $this->content($post, $withContent);
    }

    /**
     * @return array<string, mixed>
     */
    public function category(Category $category): array
    {
        return [
            'id' => $category->getId(),
            'locale' => $category->getLocale(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'url' => $this->siteUrls->generate('app_blog_category', ['slug' => $category->getSlug()], $category->getLocale(), absolute: true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function media(Media $media): array
    {
        $variants = [];
        foreach ($media->getVariants() as $name => $variant) {
            $variants[$name] = ['url' => $this->absolute($this->cms->mediaUrl($media, $name)), 'width' => $variant['width'], 'height' => $variant['height']];
        }

        return [
            'id' => $media->getId(),
            'url' => $this->absolute($this->cms->mediaUrl($media)),
            'alt' => $media->getAlt(),
            'mime_type' => $media->getMimeType(),
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
            'size' => $media->getSize(),
            'focal_point' => ['x' => $media->getFocalX(), 'y' => $media->getFocalY()],
            // Smaller versions of the image; an empty object when the original is small itself.
            'variants' => (object) $variants,
        ];
    }

    /**
     * @param list<array{label: string, url: string, current: bool, children: list<array{label: string, url: string, current: bool, children: array{}}>}> $items
     *
     * @return list<array<string, mixed>>
     */
    public function menu(array $items): array
    {
        return array_map(fn (array $item): array => [
            'label' => $item['label'],
            'url' => $this->absolute($item['url']),
            'children' => $this->menu($item['children']),
        ], $items);
    }

    /**
     * Makes an address of this website complete; other addresses are left as they are.
     */
    public function absolute(string $url): string
    {
        $request = $this->requestStack->getMainRequest();

        return null !== $request && str_starts_with($url, '/') && !str_starts_with($url, '//') ? $request->getSchemeAndHttpHost().$url : $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(Content $content, bool $withContent): array
    {
        $translations = [];
        foreach ($this->translator->versions($content) as $locale => $version) {
            if ($version !== $content && $version->isPublic()) {
                $translations[] = ['locale' => $locale, 'id' => $version->getId(), 'url' => $this->siteUrls->content($version, absolute: true)];
            }
        }

        $data = [
            'locale' => $content->getLocale(),
            'title' => $content->getTitle(),
            'slug' => $content->getSlug(),
            'url' => $this->siteUrls->content($content, absolute: true),
            'seo_title' => $content->getSeoTitle(),
            'meta_description' => $content->getMetaDescription(),
            'share_image' => null !== $content->getShareImage() ? $this->media($content->getShareImage()) : null,
            'created_at' => $content->getCreatedAt()->format(\DATE_ATOM),
            'updated_at' => $content->getUpdatedAt()->format(\DATE_ATOM),
            'translations' => $translations,
        ];

        if ($withContent) {
            // The same blocks a visitor of the website gets, extensions included.
            $blocks = $this->contentManager->prepareRendering($content)->getBlocks();
            $data['blocks'] = array_values($blocks);
            $data['html'] = $this->renderer->render($blocks);
        }

        return $data;
    }
}
