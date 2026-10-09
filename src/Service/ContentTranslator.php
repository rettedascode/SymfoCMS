<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\Page;
use App\Entity\Post;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds and creates the language versions of pages and posts.
 */
final class ContentTranslator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentLocales $locales,
        private readonly SlugGenerator $slugGenerator,
        private readonly ContentManager $contentManager,
    ) {
    }

    /**
     * All language versions of the given content, including itself, keyed by language.
     *
     * @template T of Content
     *
     * @param T $content
     *
     * @return array<string, T>
     */
    public function versions(Content $content): array
    {
        $versions = [];
        foreach ($this->entityManager->getRepository($content::class)->findBy(['translationGroup' => $content->getTranslationGroup()]) as $version) {
            $versions[$version->getLocale()] = $version;
        }
        $versions[$content->getLocale()] = $content;

        return $versions;
    }

    /**
     * The other languages of the site, each with its version of the content (or null if there is none yet).
     *
     * @template T of Content
     *
     * @param T $content
     *
     * @return array<string, T|null>
     */
    public function overview(Content $content): array
    {
        $versions = $this->versions($content);
        $overview = [];
        foreach ($this->locales->others($content->getLocale()) as $locale) {
            $overview[$locale] = $versions[$locale] ?? null;
        }

        return $overview;
    }

    /**
     * Creates a draft in the target language that starts as a copy of the
     * given content. Returns the existing version if there already is one.
     *
     * @template T of Content
     *
     * @param T $source
     *
     * @return T
     */
    public function translate(Content $source, string $locale): Content
    {
        $existing = $this->versions($source)[$locale] ?? null;
        if (null !== $existing) {
            return $existing;
        }

        // Start from a fresh entity: a draft, not the homepage, with new dates.
        $translation = new ($source::class)();
        $translation
            ->setLocale($locale)
            ->setTranslationGroup($source->getTranslationGroup())
            ->setTitle($source->getTitle())
            ->setSlug($this->slugGenerator->unique($source->getSlug(), $source::class, $locale))
            ->setSeoTitle($source->getSeoTitle())
            ->setMetaDescription($source->getMetaDescription());

        if ($source instanceof Post && $translation instanceof Post) {
            // Categories belong to one language, so the copy starts without one.
            $translation
                ->setExcerpt($source->getExcerpt())
                ->setCoverImage($source->getCoverImage())
                ->setAuthor($source->getAuthor());
        }

        \assert($translation instanceof Page || $translation instanceof Post);
        $this->contentManager->save($translation, $source->getBlocks());

        return $translation;
    }
}
