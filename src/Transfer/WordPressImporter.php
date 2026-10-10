<?php

namespace App\Transfer;

use App\Audit\AuditLogger;
use App\Block\BlockNormalizer;
use App\Entity\Category;
use App\Entity\Content;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Service\RedirectManager;
use App\Service\SiteUrls;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports a WordPress export file (WXR, "Tools → Export" in WordPress):
 * posts, pages, categories and the images they use.
 *
 * Content is converted to blocks by WordPressContentConverter. Everything
 * that has no counterpart ends up in the report instead of being dropped
 * silently.
 */
final class WordPressImporter
{
    /** Images are downloaded one by one; this keeps a huge site from running for hours. */
    private const MAX_IMAGES = 1000;

    /** @var array<int, string> attachment id => address of the file */
    private array $attachments = [];
    /** @var array<string, Media|false> address => image, or false when it could not be fetched */
    private array $images = [];
    /** @var array<string, Category> slug in WordPress => category */
    private array $categories = [];
    private int $downloads = 0;
    private ?User $user = null;
    private ImportReport $report;
    private string $baseUrl = '';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WordPressContentConverter $converter,
        private readonly ImageDownloader $downloader,
        private readonly BlockNormalizer $normalizer,
        private readonly SlugGenerator $slugs,
        private readonly SiteUrls $siteUrls,
        private readonly RedirectManager $redirects,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param string $locale language the imported content is written in
     *
     * @throws \InvalidArgumentException with a translation key when the file is not a WordPress export
     */
    public function import(string $path, string $locale, ?User $user): ImportReport
    {
        $channel = $this->read($path);
        $namespaces = $channel->getDocNamespaces(true);
        $wp = $namespaces['wp'] ?? null;
        if (null === $wp || !str_contains($wp, 'wordpress.org/export')) {
            throw new \InvalidArgumentException('transfer.wordpress.error.not_wxr');
        }
        $contentNs = $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $excerptNs = $namespaces['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/';

        $report = $this->report = new ImportReport();
        $this->attachments = $this->images = $this->categories = [];
        $this->downloads = 0;
        $this->user = $user;
        $this->baseUrl = rtrim((string) $channel->children($wp)->base_site_url ?: (string) $channel->link, '/');

        foreach ($channel->children($wp)->category as $category) {
            $this->category((string) $category->category_nicename, (string) $category->cat_name, $locale, $report);
        }

        // First pass: where the files of the attachments are.
        foreach ($channel->item as $item) {
            $meta = $item->children($wp);
            if ('attachment' === (string) $meta->post_type && '' !== (string) $meta->attachment_url) {
                $this->attachments[(int) $meta->post_id] = (string) $meta->attachment_url;
            }
        }

        $skippedTypes = [];
        $comments = 0;
        foreach ($channel->item as $item) {
            $meta = $item->children($wp);
            $type = (string) $meta->post_type;
            $status = (string) $meta->status;

            if (!\in_array($type, ['post', 'page'], true)) {
                if (!\in_array($type, ['attachment', 'nav_menu_item', 'revision', 'custom_css', 'customize_changeset', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part', 'oembed_cache'], true)) {
                    $skippedTypes[$type] = ($skippedTypes[$type] ?? 0) + 1;
                }
                continue;
            }
            if (\in_array($status, ['trash', 'auto-draft', 'inherit'], true)) {
                continue;
            }

            $comments += \count($meta->comment);
            $this->content($item, $meta, (string) $item->children($contentNs)->encoded, (string) $item->children($excerptNs)->encoded, $locale, $report);
        }

        foreach ($skippedTypes as $type => $count) {
            $report->issue((string) $type, 'transfer.wordpress.issue.type', ['%count%' => (string) $count]);
        }
        if ($comments > 0) {
            $report->issue((string) $comments, 'transfer.wordpress.issue.comments');
        }

        $this->audit->log('imported', 'wordpress', null, (string) $channel->title, ['counts' => $report->counts()], $user);

        return $report;
    }

    private function read(string $path): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        // No network access and no entity substitution: the file cannot make the server read other files.
        $loaded = $document->load($path, \LIBXML_NONET | \LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || null !== $document->doctype || 'rss' !== $document->documentElement?->nodeName) {
            throw new \InvalidArgumentException('transfer.wordpress.error.not_wxr');
        }

        $channel = simplexml_import_dom($document)?->channel;
        if (!$channel instanceof \SimpleXMLElement || 0 === $channel->count()) {
            throw new \InvalidArgumentException('transfer.wordpress.error.not_wxr');
        }

        return $channel;
    }

    private function content(\SimpleXMLElement $item, \SimpleXMLElement $meta, string $html, string $excerpt, string $locale, ImportReport $report): void
    {
        $isPost = 'post' === (string) $meta->post_type;
        $title = mb_substr(trim(html_entity_decode((string) $item->title, \ENT_QUOTES | \ENT_HTML5)), 0, 255) ?: 'Untitled';
        $status = (string) $meta->status;
        $isProtected = '' !== (string) $meta->post_password;

        $converted = $this->converter->convert(
            $html,
            fn (string $url, string $alt): ?int => $this->image($url, $alt)?->getId(),
            fn (int $id): ?int => isset($this->attachments[$id]) ? $this->image($this->attachments[$id], '')?->getId() : null,
        );
        foreach ($converted['notes'] as $note) {
            [$key, $detail] = explode('|', $note, 2);
            $report->issue($title, $key, ['%detail%' => $detail]);
        }

        $content = $isPost ? new Post() : new Page();
        $content
            ->setLocale($locale)
            ->setTitle($title)
            ->setSlug($this->slugs->unique(rawurldecode((string) $meta->post_name) ?: $title, $content::class, $locale))
            // Only what was public in WordPress goes online; a password cannot be taken over.
            ->setStatus(\in_array($status, ['publish', 'future'], true) && !$isProtected ? ContentStatus::Published : ContentStatus::Draft)
            ->setBlocks($this->normalizer->normalize($converted['blocks']));

        if ($isProtected) {
            $report->issue($title, 'transfer.wordpress.issue.password');
        }

        if ($content instanceof Post) {
            $date = $this->date((string) $meta->post_date_gmt) ?? $this->date((string) $meta->post_date);
            $content
                ->setAuthor($this->user)
                ->setExcerpt(mb_substr(trim(strip_tags(html_entity_decode($excerpt, \ENT_QUOTES | \ENT_HTML5))), 0, 500) ?: null)
                ->setPublishedAt($date ?? (ContentStatus::Published === $content->getStatus() ? new \DateTimeImmutable() : null))
                ->setCategory($this->firstCategory($item, $locale, $report))
                ->setCoverImage($this->featuredImage($meta));
        }

        $this->entityManager->persist($content);
        $this->entityManager->flush();
        $report->count($isPost ? 'posts' : 'pages');

        $this->redirectOldAddress((string) $item->link, $content);
    }

    /**
     * Visitors and search engines that still know the WordPress address are sent to the new one.
     */
    private function redirectOldAddress(string $link, Content $content): void
    {
        $query = (string) parse_url($link, \PHP_URL_QUERY);
        $old = '' === $query ? $this->redirects->normalizeSource((string) parse_url($link, \PHP_URL_PATH)) : null;
        $new = $this->siteUrls->content($content);

        if ($content->isPublic() && null !== $old && '/' !== $old && $old !== $new) {
            $this->redirects->addAutomatic($old, $new);
        }
    }

    private function firstCategory(\SimpleXMLElement $item, string $locale, ImportReport $report): ?Category
    {
        foreach ($item->category as $category) {
            if ('category' === (string) $category['domain'] && 'uncategorized' !== (string) $category['nicename']) {
                return $this->category((string) $category['nicename'], (string) $category, $locale, $report);
            }
        }

        return null;
    }

    private function category(string $nicename, string $name, string $locale, ImportReport $report): ?Category
    {
        $name = mb_substr(trim(html_entity_decode($name, \ENT_QUOTES | \ENT_HTML5)), 0, 120);
        $slug = $this->slugs->slugify(rawurldecode($nicename) ?: $name);
        if ('' === $name || '' === $slug || 'uncategorized' === $slug) {
            return null;
        }

        return $this->categories[$slug] ??= $this->entityManager->getRepository(Category::class)->findOneBy(['locale' => $locale, 'slug' => $slug])
            ?? $this->createCategory($name, $slug, $locale, $report);
    }

    private function createCategory(string $name, string $slug, string $locale, ImportReport $report): Category
    {
        $category = (new Category())->setLocale($locale)->setName($name)->setSlug($slug);
        $this->entityManager->persist($category);
        $this->entityManager->flush();
        $report->count('categories');

        return $category;
    }

    private function featuredImage(\SimpleXMLElement $meta): ?Media
    {
        foreach ($meta->postmeta as $entry) {
            if ('_thumbnail_id' === (string) $entry->meta_key) {
                $url = $this->attachments[(int) $entry->meta_value] ?? null;

                return null !== $url ? $this->image($url, '') : null;
            }
        }

        return null;
    }

    /**
     * The image behind an address, downloaded on first use.
     */
    private function image(string $url, string $alt): ?Media
    {
        $url = html_entity_decode(trim($url), \ENT_QUOTES | \ENT_HTML5);
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = $this->baseUrl.$url;
        }

        // WordPress links to resized copies ("photo-300x200.jpg", "photo-scaled.jpg"): the original is what we want.
        $original = (string) preg_replace('~-(?:\d+x\d+|scaled)(\.[a-z0-9]+)(\?.*)?$~i', '$1', $url);

        foreach (array_unique([$original, $url]) as $candidate) {
            if (!isset($this->images[$candidate])) {
                if ($this->downloads >= self::MAX_IMAGES) {
                    return null;
                }
                ++$this->downloads;
                $this->images[$candidate] = $this->downloader->download($candidate, $this->user) ?? false;
                if (false !== $this->images[$candidate]) {
                    $this->report->count('media');
                }
            }

            $media = $this->images[$candidate];
            if ($media instanceof Media) {
                if ('' === $media->getAlt() && '' !== $alt) {
                    $media->setAlt(mb_substr($alt, 0, 255));
                }

                return $media;
            }
        }

        return null;
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        if ('' === $value || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            // WordPress exports the time in UTC; dates are stored in the time zone of the website.
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }
}
