<?php

namespace App\Transfer;

use App\Audit\AuditLogger;
use App\Block\BlockNormalizer;
use App\Entity\Category;
use App\Entity\Content;
use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Entity\Media;
use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\PageTemplate;
use App\Entity\Post;
use App\Entity\Redirect;
use App\Entity\Revision;
use App\Entity\Setting;
use App\Entity\SharedBlock;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\MenuItemType;
use App\Enum\MenuLocation;
use App\Exception\InvalidUploadException;
use App\Service\ContentLocales;
use App\Service\FormDefinition;
use App\Service\LinkSanitizer;
use App\Service\MediaManager;
use App\Service\PageTree;
use App\Service\RedirectManager;
use App\Service\SearchIndexer;
use App\Service\Settings;
use App\Service\SlugGenerator;
use App\Theme\ThemeRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Reads a ZIP file written by SiteExporter into this installation.
 *
 * The file is treated like any other input from outside: every text goes
 * through the same cleaning as content typed into the editor, images are
 * checked like uploads, and nothing from the archive is unpacked to a path
 * the archive chooses.
 */
final class SiteImporter
{
    private const MAX_JSON_BYTES = 64 * 1024 * 1024;

    /** @var array<int, Media> id in the export => image in this installation */
    private array $media = [];
    /** @var array<int, int> */
    private array $sharedBlocks = [];
    /** @var array<int, int> */
    private array $forms = [];
    /** @var array<int, Category> */
    private array $categories = [];
    /** @var array<int, Page> */
    private array $pages = [];
    /** @var array<int, Post> */
    private array $posts = [];
    /** @var array<string, string> translation group in the export => group in this installation */
    private array $groups = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaManager $mediaManager,
        private readonly BlockNormalizer $normalizer,
        private readonly BlockReferences $references,
        private readonly FormDefinition $formDefinition,
        private readonly SlugGenerator $slugs,
        private readonly ContentLocales $locales,
        private readonly Settings $settings,
        private readonly ThemeRegistry $themes,
        private readonly RedirectManager $redirects,
        private readonly LinkSanitizer $linkSanitizer,
        private readonly SearchIndexer $searchIndexer,
        private readonly AuditLogger $audit,
        #[Autowire(param: 'app.media_dir')]
        private readonly string $mediaDir,
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $tempDir,
    ) {
    }

    /**
     * @param bool      $replace true: the content of this website is deleted first and the settings of the
     *                           export are taken over. False: everything is added to what is already there.
     * @param User|null $user    becomes the author of posts whose author does not exist here
     *
     * @throws \InvalidArgumentException with a translation key when the file is not an export of this software
     */
    public function import(string $zipPath, bool $replace, ?User $user = null): ImportReport
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath, \ZipArchive::RDONLY)) {
            throw new \InvalidArgumentException('transfer.error.not_a_zip');
        }

        try {
            $data = $this->readManifest($zip);
            $report = new ImportReport();
            $userId = $user?->getId();
            $this->media = $this->sharedBlocks = $this->forms = $this->categories = $this->pages = $this->posts = $this->groups = [];

            $connection = $this->entityManager->getConnection();
            $connection->beginTransaction();
            $obsoleteFiles = [];
            $createdBefore = $this->mediaFiles();

            try {
                if ($replace) {
                    $obsoleteFiles = $this->deleteContent();
                    $user = null !== $userId ? $this->entityManager->find(User::class, $userId) : null;
                }

                $this->importMedia($zip, $this->rows($data, 'media'), $user, $report);
                $this->importCategories($this->rows($data, 'categories'), $report);
                $this->importForms($this->rows($data, 'forms'), $report);
                $this->importSharedBlocks($this->rows($data, 'shared_blocks'), $report);
                $this->importTemplates($this->rows($data, 'page_templates'), $report);
                $this->importPages($this->rows($data, 'pages'), $replace, $report);
                $this->importPosts($this->rows($data, 'posts'), $user, $report);
                $this->importMenus($this->rows($data, 'menus'), $report);
                $this->importRedirects($this->rows($data, 'redirects'), $report);
                $this->importSettings(\is_array($data['settings'] ?? null) ? $data['settings'] : [], $replace);

                $this->entityManager->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                $connection->rollBack();
                // Images stored by the failed import would be left behind without a database entry.
                $this->removeFiles(array_diff($this->mediaFiles(), $createdBefore));

                throw $exception;
            }

            $this->removeFiles($obsoleteFiles);
            $this->searchIndexer->rebuild();
            $this->audit->log('imported', 'site', null, $replace ? 'replace' : 'add', ['counts' => $report->counts()], $user);

            return $report;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(\ZipArchive $zip): array
    {
        $stat = $zip->statName('site.json');
        if (false === $stat || $stat['size'] > self::MAX_JSON_BYTES) {
            throw new \InvalidArgumentException('transfer.error.not_an_export');
        }

        try {
            $data = json_decode((string) $zip->getFromName('site.json'), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('transfer.error.not_an_export');
        }

        if (!\is_array($data) || SiteExporter::FORMAT !== ($data['format'] ?? null)) {
            throw new \InvalidArgumentException('transfer.error.not_an_export');
        }
        if (!\is_int($data['version'] ?? null) || $data['version'] > SiteExporter::VERSION) {
            throw new \InvalidArgumentException('transfer.error.newer_version');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $data, string $key): array
    {
        return array_values(array_filter(\is_array($data[$key] ?? null) ? $data[$key] : [], \is_array(...)));
    }

    /**
     * Removes pages, posts, menus, images and everything else an export contains. Users stay.
     *
     * @return list<string> image files to delete once the import has succeeded
     */
    private function deleteContent(): array
    {
        $files = $this->mediaFiles(fromDatabase: true);

        $this->entityManager->createQuery('DELETE FROM '.MenuItem::class.' m WHERE m.parent IS NOT NULL')->execute();
        foreach ([MenuItem::class, Revision::class, Post::class, Page::class, Category::class, SharedBlock::class, PageTemplate::class, FormSubmission::class, Form::class, Redirect::class, Media::class] as $class) {
            $this->entityManager->createQuery('DELETE FROM '.$class.' e')->execute();
        }
        $this->entityManager->clear();

        return $files;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function importMedia(\ZipArchive $zip, array $rows, ?User $user, ImportReport $report): void
    {
        $filesystem = new Filesystem();

        foreach ($rows as $row) {
            $name = $this->string($row, 'original_name') ?: 'image';
            // Only a plain file name inside "media/" is ever read from the archive.
            $entry = 'media/'.basename($this->string($row, 'file'));
            $stream = 1 === preg_match('#^media/[\w.-]+$#', $entry) ? $zip->getStream($entry) : false;
            if (false === $stream) {
                $report->issue($name, 'transfer.issue.image_missing');
                continue;
            }

            $temp = $filesystem->tempnam($this->tempDir, 'import');
            file_put_contents($temp, $stream);
            fclose($stream);

            try {
                $media = $this->mediaManager->upload(new UploadedFile($temp, mb_substr($name, 0, 255), null, null, true), $user);
                $media->setAlt(mb_substr($this->string($row, 'alt'), 0, 255));
                $focus = $row['focal_point'] ?? null;
                if (\is_array($focus) && is_numeric($focus[0] ?? null) && is_numeric($focus[1] ?? null) && [0.5, 0.5] !== [(float) $focus[0], (float) $focus[1]]) {
                    $this->mediaManager->setFocalPoint($media, (float) $focus[0], (float) $focus[1]);
                }
                $this->media[(int) ($row['id'] ?? 0)] = $media;
                $report->count('media');
            } catch (InvalidUploadException) {
                $report->issue($name, 'transfer.issue.image_invalid');
            } finally {
                $filesystem->remove($temp);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function importCategories(array $rows, ImportReport $report): void
    {
        foreach ($rows as $row) {
            $locale = $this->locale($row);
            $name = mb_substr($this->string($row, 'name'), 0, 120);
            if (null === $locale || '' === $name) {
                continue;
            }

            $slug = $this->slugs->slugify($this->string($row, 'slug') ?: $name) ?: 'category';
            // A category that exists already is used instead of creating a twin.
            $category = $this->entityManager->getRepository(Category::class)->findOneBy(['locale' => $locale, 'slug' => $slug]);
            if (null === $category) {
                $category = (new Category())->setLocale($locale)->setName($name)->setSlug($slug);
                $this->entityManager->persist($category);
                $this->entityManager->flush();
                $report->count('categories');
            }

            $this->categories[(int) ($row['id'] ?? 0)] = $category;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function importForms(array $rows, ImportReport $report): void
    {
        foreach ($rows as $row) {
            $email = filter_var($row['notify_email'] ?? null, \FILTER_VALIDATE_EMAIL);
            $form = (new Form())
                ->setName(mb_substr($this->string($row, 'name') ?: 'Form', 0, 120))
                ->setFields($this->formDefinition->normalize($row['fields'] ?? []))
                ->setSubmitLabel(mb_substr($this->string($row, 'submit_label'), 0, 60) ?: null)
                ->setSuccessMessage(mb_substr($this->string($row, 'success_message'), 0, 500) ?: null)
                ->setNotifyEmail(false !== $email ? $email : null);
            $this->entityManager->persist($form);
            $this->entityManager->flush();

            $this->forms[(int) ($row['id'] ?? 0)] = (int) $form->getId();
            $report->count('forms');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function importSharedBlocks(array $rows, ImportReport $report): void
    {
        foreach ($rows as $row) {
            $block = (new SharedBlock())
                ->setName(mb_substr($this->string($row, 'name') ?: 'Shared block', 0, 120))
                ->setBlocks($this->blocks($row['blocks'] ?? [], allowShared: false));
            $this->entityManager->persist($block);
            $this->entityManager->flush();

            $this->sharedBlocks[(int) ($row['id'] ?? 0)] = (int) $block->getId();
            $report->count('shared_blocks');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function importTemplates(array $rows, ImportReport $report): void
    {
        foreach ($rows as $row) {
            $this->entityManager->persist(new PageTemplate(mb_substr($this->string($row, 'name') ?: 'Template', 0, 120), $this->blocks($row['blocks'] ?? [])));
            $report->count('page_templates');
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function importPages(array $rows, bool $replace, ImportReport $report): void
    {
        foreach ($rows as $row) {
            $page = new Page();
            if (!$this->fillContent($page, $row, $report)) {
                continue;
            }

            // A website has one homepage per language: the one that is already there stays.
            $hasHomepage = !$replace && null !== $this->entityManager->getRepository(Page::class)->findOneBy(['homepage' => true, 'locale' => $page->getLocale()]);
            $isFirstInImport = [] === array_filter($this->pages, static fn (Page $other): bool => $other->isHomepage() && $other->getLocale() === $page->getLocale());
            $page->setHomepage(true === ($row['homepage'] ?? false) && !$hasHomepage && $isFirstInImport);

            $page->setPosition(max(0, (int) ($row['position'] ?? 0)));

            $this->entityManager->persist($page);
            $this->entityManager->flush();
            $this->pages[(int) ($row['id'] ?? 0)] = $page;
            $report->count('pages');
        }

        // Second pass, now that every page exists: put child pages below their parents again.
        foreach ($rows as $row) {
            $page = $this->pages[(int) ($row['id'] ?? 0)] ?? null;
            $parent = $this->pages[(int) ($row['parent'] ?? 0)] ?? null;
            if (null === $page || null === $parent || $page === $parent || $page->isHomepage() || $parent->isHomepage()
                || $parent->getLocale() !== $page->getLocale() || $parent->getDepth() >= PageTree::MAX_DEPTH) {
                continue;
            }

            // Never a loop, whatever the file says.
            for ($ancestor = $parent; null !== $ancestor; $ancestor = $ancestor->getParent()) {
                if ($ancestor === $page) {
                    continue 2;
                }
            }
            $page->setParent($parent);
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function importPosts(array $rows, ?User $fallbackAuthor, ImportReport $report): void
    {
        foreach ($rows as $row) {
            $post = new Post();
            if (!$this->fillContent($post, $row, $report)) {
                continue;
            }

            $email = $this->string($row, 'author_email');
            $author = '' !== $email ? $this->entityManager->getRepository(User::class)->findOneBy(['email' => mb_strtolower($email)]) : null;
            $category = $this->categories[(int) ($row['category'] ?? 0)] ?? null;

            try {
                $publishedAt = \is_string($row['published_at'] ?? null) ? new \DateTimeImmutable($row['published_at']) : null;
            } catch (\Exception) {
                $publishedAt = null;
            }

            $post
                ->setExcerpt(mb_substr($this->string($row, 'excerpt'), 0, 500) ?: null)
                ->setPublishedAt($publishedAt ?? (ContentStatus::Published === $post->getStatus() ? new \DateTimeImmutable() : null))
                ->setCoverImage($this->media[(int) ($row['cover_image'] ?? 0)] ?? null)
                // Categories belong to one language.
                ->setCategory(null !== $category && $category->getLocale() === $post->getLocale() ? $category : null)
                ->setAuthor($author ?? $fallbackAuthor);

            $this->entityManager->persist($post);
            $this->entityManager->flush();
            $this->posts[(int) ($row['id'] ?? 0)] = $post;
            $report->count('posts');
        }
    }

    /**
     * Sets what pages and posts have in common. Returns false when the row cannot be used.
     *
     * @param array<string, mixed> $row
     */
    private function fillContent(Content $content, array $row, ImportReport $report): bool
    {
        $locale = $this->locale($row);
        $title = mb_substr($this->string($row, 'title'), 0, 255);
        if (null === $locale || '' === $title) {
            $report->issue($title ?: '?', 'transfer.issue.content_invalid');

            return false;
        }

        // Translations stay linked to each other, but never to content that was here before.
        $group = $this->string($row, 'translation_group');
        $this->groups[$group] ??= bin2hex(random_bytes(16));
        $versions = $this->entityManager->getRepository($content::class)->findBy(['translationGroup' => $this->groups[$group], 'locale' => $locale]);

        $content
            ->setLocale($locale)
            ->setTranslationGroup([] === $versions && '' !== $group ? $this->groups[$group] : bin2hex(random_bytes(16)))
            ->setTitle($title)
            ->setSlug($this->slugs->unique($this->string($row, 'slug') ?: $title, $content::class, $locale))
            ->setStatus(ContentStatus::tryFrom($this->string($row, 'status')) ?? ContentStatus::Draft)
            ->setSeoTitle(mb_substr($this->string($row, 'seo_title'), 0, 255) ?: null)
            ->setMetaDescription(mb_substr($this->string($row, 'meta_description'), 0, 500) ?: null)
            ->setShareImage($this->media[(int) ($row['share_image'] ?? 0)] ?? null)
            ->setBlocks($this->blocks($row['blocks'] ?? []));

        return true;
    }

    /**
     * @param list<array<string, mixed>> $menus
     */
    private function importMenus(array $menus, ImportReport $report): void
    {
        foreach ($menus as $menu) {
            $location = MenuLocation::tryFrom($this->string($menu, 'location'));
            $locale = $this->locale($menu);
            if (null === $location || null === $locale) {
                continue;
            }

            // New entries are placed after the ones that are already there.
            $position = $this->entityManager->getRepository(MenuItem::class)->count(['location' => $location, 'locale' => $locale, 'parent' => null]);
            foreach ($this->rows($menu, 'items') as $row) {
                $item = $this->menuItem($row, $location, $locale, $position, null, $report);
                if (null === $item) {
                    continue;
                }
                ++$position;

                foreach ($this->rows($row, 'children') as $childPosition => $child) {
                    $this->menuItem($child, $location, $locale, $childPosition, $item, $report);
                }
            }
        }
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function menuItem(array $row, MenuLocation $location, string $locale, int $position, ?MenuItem $parent, ImportReport $report): ?MenuItem
    {
        $type = MenuItemType::tryFrom($this->string($row, 'type'));
        $label = mb_substr($this->string($row, 'label'), 0, 120);
        $page = MenuItemType::Page === $type ? ($this->pages[(int) ($row['page'] ?? 0)] ?? null) : null;
        $post = MenuItemType::Post === $type ? ($this->posts[(int) ($row['post'] ?? 0)] ?? null) : null;
        $url = MenuItemType::Url === $type ? $this->linkSanitizer->sanitize($row['url'] ?? null) : '';

        if (null === $type || '' === $label || (null === $page && null === $post && '' === $url)) {
            $report->issue($label ?: '?', 'transfer.issue.menu_item');

            return null;
        }

        $item = (new MenuItem())
            ->setLocation($location)
            ->setLocale($locale)
            ->setType($type)
            ->setLabel($label)
            ->setPage($page)
            ->setPost($post)
            ->setUrl('' !== $url ? $url : null)
            ->setParent($parent)
            ->setPosition($position);
        $this->entityManager->persist($item);

        return $item;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function importRedirects(array $rows, ImportReport $report): void
    {
        $repository = $this->entityManager->getRepository(Redirect::class);

        foreach ($rows as $row) {
            $source = $this->redirects->normalizeSource($this->string($row, 'source'));
            $target = $this->linkSanitizer->sanitize($row['target'] ?? null);
            if (null === $source || '' === $source || '' === $target || $source === $target || null !== $repository->findOneBy(['source' => $source])) {
                continue;
            }

            $this->entityManager->persist((new Redirect())->setSource($source)->setTarget(mb_substr($target, 0, 500))->setAutomatic(true === ($row['automatic'] ?? false)));
            $this->entityManager->flush();
            $report->count('redirects');
        }
    }

    /**
     * @param array<mixed, mixed> $settings
     */
    private function importSettings(array $settings, bool $replace): void
    {
        // Every language that now has content is switched on, whatever the mode.
        $used = array_unique(array_map(static fn (Content $content): string => $content->getLocale(), [...$this->pages, ...$this->posts]));
        $locales = $this->locales->all();

        if ($replace) {
            $exported = array_values(array_filter(\is_array($settings['locales'] ?? null) ? $settings['locales'] : [], static fn (mixed $locale): bool => \is_string($locale) && ContentLocales::isValidCode($locale)));
            $locales = [] !== $exported ? $exported : $locales;

            // Settings of the old website that the export does not mention go back to their defaults.
            foreach ($this->entityManager->getRepository(Setting::class)->findAll() as $setting) {
                if ('locales' !== $setting->getName() && 1 === preg_match(SiteExporter::SETTINGS, $setting->getName()) && !\array_key_exists($setting->getName(), $settings)) {
                    $this->settings->set($setting->getName(), null);
                }
            }

            foreach ($settings as $name => $value) {
                if (!\is_string($name) || 'locales' === $name || 1 !== preg_match(SiteExporter::SETTINGS, $name) || !(\is_scalar($value) || \is_array($value))) {
                    continue;
                }

                if ('theme.logo' === $name) {
                    $value = is_numeric($value) ? ($this->media[(int) $value] ?? null)?->getId() : null;
                } elseif ('theme.active' === $name && (!\is_string($value) || null === $this->themes->get($value))) {
                    $value = null;
                }
                $this->settings->set($name, $value);
            }
        }

        $this->locales->save(\array_slice(array_values(array_unique([...$locales, ...$used])), 0, ContentLocales::MAX_LOCALES));
    }

    /**
     * Blocks from the archive with their references rewritten, cleaned like blocks sent by the editor.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function blocks(mixed $blocks, bool $allowShared = true): array
    {
        $media = array_map(static fn (Media $media): int => (int) $media->getId(), $this->media);

        return $this->normalizer->normalize($this->references->remap(\is_array($blocks) ? $blocks : [], $media, $this->sharedBlocks, $this->forms), allowShared: $allowShared);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $key): string
    {
        return \is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function locale(array $row): ?string
    {
        $locale = $this->string($row, 'locale');

        return ContentLocales::isValidCode($locale) ? $locale : null;
    }

    /**
     * @return list<string> file names in the media folder, or those the database knows about
     */
    private function mediaFiles(bool $fromDatabase = false): array
    {
        if (!$fromDatabase) {
            return array_map(basename(...), glob($this->mediaDir.'/*') ?: []);
        }

        $files = [];
        foreach ($this->entityManager->getRepository(Media::class)->findAll() as $media) {
            array_push($files, $media->getFilename(), ...array_column($media->getVariants(), 'file'));
        }

        return $files;
    }

    /**
     * @param array<array-key, string> $files
     */
    private function removeFiles(array $files): void
    {
        $filesystem = new Filesystem();
        foreach ($files as $file) {
            // Only files with the names the media library gives them are ever deleted.
            if (1 === preg_match('/^[a-f0-9]+(-[a-z]+)?\.[a-z]+$/', $file)) {
                $filesystem->remove($this->mediaDir.'/'.$file);
            }
        }
    }
}
