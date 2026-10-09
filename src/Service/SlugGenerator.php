<?php

namespace App\Service;

use App\Entity\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Turns titles into URL slugs that are unique for the given entity class and language.
 */
final class SlugGenerator
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentLocales $locales,
    ) {
    }

    public function slugify(string $text): string
    {
        return mb_substr($this->slugger->slug($text)->lower()->toString(), 0, 180);
    }

    /**
     * @param class-string $entityClass entity with "id", "slug" and "locale" fields
     */
    public function unique(string $text, string $entityClass, string $locale, ?int $ignoreId = null): string
    {
        $base = $this->slugify($text);
        if ('' === $base) {
            $base = 'untitled';
        }

        $slug = $base;
        $suffix = 2;
        while ($this->isTaken($slug, $entityClass, $locale, $ignoreId)) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Page addresses that would collide with the application's own URLs:
     * fixed first path segments and the language prefixes ("/de").
     */
    public function isReservedForPages(string $slug): bool
    {
        return \in_array($slug, Page::RESERVED_SLUGS, true) || $this->locales->isEnabled($slug);
    }

    /**
     * @param class-string $entityClass
     */
    private function isTaken(string $slug, string $entityClass, string $locale, ?int $ignoreId): bool
    {
        if (Page::class === $entityClass && $this->isReservedForPages($slug)) {
            return true;
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($entityClass, 'e')
            ->where('e.slug = :slug')
            ->andWhere('e.locale = :locale')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale);

        if (null !== $ignoreId) {
            $qb->andWhere('e.id != :id')->setParameter('id', $ignoreId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
