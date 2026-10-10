<?php

namespace App\Transfer;

use App\Audit\AuditLogger;
use App\Entity\Category;
use App\Entity\Content;
use App\Entity\Form;
use App\Entity\Media;
use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\PageTemplate;
use App\Entity\Post;
use App\Entity\Redirect;
use App\Entity\Setting;
use App\Entity\SharedBlock;
use App\Enum\MenuLocation;
use App\Repository\MenuItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes the whole website into one ZIP file: a "site.json" with content,
 * menus and settings, and the original images in "media/".
 *
 * Not included on purpose: users and their passwords, earlier versions of
 * pages, and what visitors sent through forms.
 */
final class SiteExporter
{
    public const FORMAT = 'symfocms-site';
    public const VERSION = 1;

    /** Settings that describe the website. Switches such as the read API are decided per installation. */
    public const SETTINGS = '/^(locales$|site\.|theme\.|forms\.)/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MenuItemRepository $menuItems,
        private readonly AuditLogger $audit,
        #[Autowire(param: 'app.media_dir')]
        private readonly string $mediaDir,
    ) {
    }

    /**
     * @return array<string, int> what was exported => how many
     */
    public function export(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(\sprintf('The file "%s" cannot be written.', $zipPath));
        }

        $data = $this->data();
        foreach ($data['media'] as $media) {
            $file = $this->mediaDir.'/'.basename($media['file']);
            if (is_file($file)) {
                $zip->addFile($file, $media['file']);
                // Images are compressed already; storing them as they are is much faster.
                $zip->setCompressionName($media['file'], \ZipArchive::CM_STORE);
            }
        }

        $zip->addFromString('site.json', json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        $zip->close();

        $counts = [];
        foreach (['pages', 'posts', 'categories', 'media', 'shared_blocks', 'forms', 'page_templates', 'redirects'] as $key) {
            $counts[$key] = \count($data[$key]);
        }

        $this->audit->log('exported', 'site', null, '', ['counts' => $counts]);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $settings = [];
        foreach ($this->entityManager->getRepository(Setting::class)->findBy([], ['name' => 'ASC']) as $setting) {
            if (1 === preg_match(self::SETTINGS, $setting->getName())) {
                $settings[$setting->getName()] = $setting->getValue();
            }
        }

        $menus = [];
        foreach ($this->entityManager->getConnection()->fetchFirstColumn('SELECT DISTINCT locale FROM menu_items ORDER BY locale') as $locale) {
            foreach (MenuLocation::cases() as $location) {
                $items = array_map($this->menuItem(...), $this->menuItems->findTree($location, (string) $locale));
                if ([] !== $items) {
                    $menus[] = ['location' => $location->value, 'locale' => (string) $locale, 'items' => $items];
                }
            }
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'settings' => (object) $settings,
            'media' => array_map(static fn (Media $media): array => [
                'id' => $media->getId(),
                'file' => 'media/'.$media->getFilename(),
                'original_name' => $media->getOriginalName(),
                'alt' => $media->getAlt(),
                'focal_point' => [$media->getFocalX(), $media->getFocalY()],
            ], $this->entityManager->getRepository(Media::class)->findBy([], ['id' => 'ASC'])),
            'categories' => array_map(static fn (Category $category): array => [
                'id' => $category->getId(),
                'locale' => $category->getLocale(),
                'name' => $category->getName(),
                'slug' => $category->getSlug(),
            ], $this->entityManager->getRepository(Category::class)->findBy([], ['id' => 'ASC'])),
            'forms' => array_map(static fn (Form $form): array => [
                'id' => $form->getId(),
                'name' => $form->getName(),
                'fields' => $form->getFields(),
                'submit_label' => $form->getSubmitLabel(),
                'success_message' => $form->getSuccessMessage(),
                'notify_email' => $form->getNotifyEmail(),
            ], $this->entityManager->getRepository(Form::class)->findBy([], ['id' => 'ASC'])),
            'shared_blocks' => array_map(static fn (SharedBlock $block): array => [
                'id' => $block->getId(),
                'name' => $block->getName(),
                'blocks' => $block->getBlocks(),
            ], $this->entityManager->getRepository(SharedBlock::class)->findBy([], ['id' => 'ASC'])),
            'page_templates' => array_map(static fn (PageTemplate $template): array => [
                'name' => $template->getName(),
                'blocks' => $template->getBlocks(),
            ], $this->entityManager->getRepository(PageTemplate::class)->findBy([], ['id' => 'ASC'])),
            'pages' => array_map(fn (Page $page): array => $this->content($page) + ['homepage' => $page->isHomepage()], $this->entityManager->getRepository(Page::class)->findBy([], ['id' => 'ASC'])),
            'posts' => array_map(fn (Post $post): array => $this->content($post) + [
                'excerpt' => $post->getExcerpt(),
                'published_at' => $post->getPublishedAt()?->format(\DATE_ATOM),
                'cover_image' => $post->getCoverImage()?->getId(),
                'category' => $post->getCategory()?->getId(),
                'author_email' => $post->getAuthor()?->getEmail(),
            ], $this->entityManager->getRepository(Post::class)->findBy([], ['id' => 'ASC'])),
            'menus' => $menus,
            'redirects' => array_map(static fn (Redirect $redirect): array => [
                'source' => $redirect->getSource(),
                'target' => $redirect->getTarget(),
                'automatic' => $redirect->isAutomatic(),
            ], $this->entityManager->getRepository(Redirect::class)->findBy([], ['id' => 'ASC'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(Content $content): array
    {
        return [
            'id' => $content->getId(),
            'locale' => $content->getLocale(),
            'translation_group' => $content->getTranslationGroup(),
            'title' => $content->getTitle(),
            'slug' => $content->getSlug(),
            'status' => $content->getStatus()->value,
            'seo_title' => $content->getSeoTitle(),
            'meta_description' => $content->getMetaDescription(),
            'share_image' => $content->getShareImage()?->getId(),
            'blocks' => $content->getBlocks(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function menuItem(MenuItem $item): array
    {
        return [
            'label' => $item->getLabel(),
            'type' => $item->getType()->value,
            'page' => $item->getPage()?->getId(),
            'post' => $item->getPost()?->getId(),
            'url' => $item->getUrl(),
            'children' => array_map($this->menuItem(...), $item->getChildren()->toArray()),
        ];
    }
}
