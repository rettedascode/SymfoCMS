<?php

namespace App\Service;

use App\Entity\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Turns titles into URL slugs that are unique for the given entity class.
 */
final class SlugGenerator
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function slugify(string $text): string
    {
        return mb_substr($this->slugger->slug($text)->lower()->toString(), 0, 180);
    }

    /**
     * @param class-string $entityClass entity with "id" and "slug" fields
     */
    public function unique(string $text, string $entityClass, ?int $ignoreId = null): string
    {
        $base = $this->slugify($text);
        if ('' === $base) {
            $base = 'untitled';
        }

        $slug = $base;
        $suffix = 2;
        while ($this->isTaken($slug, $entityClass, $ignoreId)) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @param class-string $entityClass
     */
    private function isTaken(string $slug, string $entityClass, ?int $ignoreId): bool
    {
        if (Page::class === $entityClass && \in_array($slug, Page::RESERVED_SLUGS, true)) {
            return true;
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($entityClass, 'e')
            ->where('e.slug = :slug')
            ->setParameter('slug', $slug);

        if (null !== $ignoreId) {
            $qb->andWhere('e.id != :id')->setParameter('id', $ignoreId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
