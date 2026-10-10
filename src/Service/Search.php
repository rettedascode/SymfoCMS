<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\SearchEntry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Full-text search over the search index.
 *
 * It is built on LIKE comparisons of lower-cased text, which every supported
 * database understands in the same way. A result has to contain all words of
 * the query; results with a word in the title come first.
 */
final class Search
{
    /** Shorter queries would match almost everything. */
    public const MIN_LENGTH = 2;
    private const MAX_LENGTH = 100;
    private const MAX_TERMS = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SearchIndexer $indexer,
    ) {
    }

    /**
     * Splits what somebody typed into the words that are searched for.
     *
     * @return list<string>
     */
    public function terms(string $query): array
    {
        $words = preg_split('/\s+/u', trim(mb_substr($query, 0, self::MAX_LENGTH)), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        foreach ($words as $word) {
            $word = SearchIndexer::fold($word);
            if (mb_strlen($word) >= self::MIN_LENGTH) {
                $terms[$word] = true;
            }
        }

        return \array_slice(array_map(strval(...), array_keys($terms)), 0, self::MAX_TERMS);
    }

    /**
     * Published pages and posts of one language for the public results page.
     *
     * @param list<string> $terms
     *
     * @return array{total: int, results: list<array{content: Content, kind: string, body: string}>}
     */
    public function findPublic(array $terms, string $locale, int $page, int $perPage): array
    {
        if ([] === $terms) {
            return ['total' => 0, 'results' => []];
        }

        $this->indexer->ensureBuilt();

        $qb = $this->match($terms, [SearchEntry::PAGE, SearchEntry::POST])
            ->andWhere('e.visible = :visible')
            ->andWhere('e.locale = :locale')
            ->andWhere('e.visibleFrom IS NULL OR e.visibleFrom <= :now')
            ->setParameter('visible', true)
            ->setParameter('locale', $locale)
            ->setParameter('now', new \DateTimeImmutable());

        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        $results = [];
        foreach ($this->entries($qb, $terms, $perPage, ($page - 1) * $perPage) as [$entry, $entity]) {
            if ($entity instanceof Content) {
                $results[] = ['content' => $entity, 'kind' => $entry->getKind(), 'body' => $entry->getBody()];
            }
        }

        return ['total' => $total, 'results' => $results];
    }

    /**
     * Everything that matches, drafts included, for the search in the admin area.
     *
     * @param list<string> $terms
     *
     * @return array{pages: list<array{entity: Page, body: string}>, posts: list<array{entity: Post, body: string}>, media: list<Media>}
     */
    public function findForAdmin(array $terms, int $limit): array
    {
        $found = ['pages' => [], 'posts' => [], 'media' => []];
        if ([] === $terms) {
            return $found;
        }

        $this->indexer->ensureBuilt();

        foreach ($this->entries($this->match($terms, [SearchEntry::PAGE]), $terms, $limit) as [$entry, $entity]) {
            if ($entity instanceof Page) {
                $found['pages'][] = ['entity' => $entity, 'body' => $entry->getBody()];
            }
        }
        foreach ($this->entries($this->match($terms, [SearchEntry::POST]), $terms, $limit) as [$entry, $entity]) {
            if ($entity instanceof Post) {
                $found['posts'][] = ['entity' => $entity, 'body' => $entry->getBody()];
            }
        }
        foreach ($this->entries($this->match($terms, [SearchEntry::MEDIA]), $terms, $limit) as [, $entity]) {
            if ($entity instanceof Media) {
                $found['media'][] = $entity;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $terms
     * @param list<string> $kinds
     */
    private function match(array $terms, array $kinds): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(SearchEntry::class, 'e')
            ->where('e.kind IN (:kinds)')
            ->setParameter('kinds', $kinds);

        foreach ($terms as $i => $term) {
            // "!" switches off the special meaning of % and _ in what the visitor typed.
            $qb->andWhere(\sprintf("e.titleFolded LIKE :term%d ESCAPE '!' OR e.bodyFolded LIKE :term%d ESCAPE '!'", $i, $i))
                ->setParameter('term'.$i, '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%');
        }

        return $qb;
    }

    /**
     * Runs the query, best matches first, and loads the page, post or image behind every entry.
     *
     * @param list<string> $terms
     *
     * @return list<array{SearchEntry, object|null}>
     */
    private function entries(QueryBuilder $qb, array $terms, int $limit, int $offset = 0): array
    {
        $score = [];
        foreach (array_keys($terms) as $i) {
            $score[] = \sprintf("(CASE WHEN e.titleFolded LIKE :term%d ESCAPE '!' THEN 1 ELSE 0 END)", $i);
        }

        /** @var list<SearchEntry> $entries */
        $entries = $qb
            ->select('e', implode(' + ', $score).' AS HIDDEN score')
            ->orderBy('score', 'DESC')
            ->addOrderBy('e.visibleFrom', 'DESC')
            ->addOrderBy('e.title', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $classes = [SearchEntry::PAGE => Page::class, SearchEntry::POST => Post::class, SearchEntry::MEDIA => Media::class];
        $ids = [];
        foreach ($entries as $entry) {
            $ids[$entry->getKind()][] = $entry->getContentId();
        }

        $entities = [];
        foreach ($ids as $kind => $kindIds) {
            foreach ($this->entityManager->getRepository($classes[$kind])->findBy(['id' => $kindIds]) as $entity) {
                $entities[$kind][(int) $entity->getId()] = $entity;
            }
        }

        return array_map(static fn (SearchEntry $entry): array => [$entry, $entities[$entry->getKind()][$entry->getContentId()] ?? null], $entries);
    }
}
