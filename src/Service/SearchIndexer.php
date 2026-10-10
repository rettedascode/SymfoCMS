<?php

namespace App\Service;

use App\Block\BlockRegistry;
use App\Block\Field;
use App\Entity\Content;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\SearchEntry;
use App\Entity\SharedBlock;
use App\Enum\ContentStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps the search index in step with the content.
 *
 * Every page, post and image has one row of plain text in the "search_index"
 * table. The rows are written with plain SQL so that indexing can happen
 * right after Doctrine stored a change (see App\EventSubscriber\SearchIndexSubscriber).
 */
final class SearchIndexer implements ResetInterface
{
    private const TABLE = 'search_index';

    private bool $built = false;

    /** @var array<int, list<mixed>> blocks of the shared blocks looked up so far */
    private array $sharedBlocks = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly BlockRegistry $registry,
    ) {
    }

    /**
     * Lower-cases text the same way for the index and for search terms.
     */
    public static function fold(string $text): string
    {
        return mb_strtolower($text);
    }

    /**
     * Fills the index when it has never been built, e.g. right after an update
     * of the software or after content was written directly to the database.
     *
     * @return bool true when the index was built by this call
     */
    public function ensureBuilt(): bool
    {
        if ($this->built) {
            return false;
        }

        $this->built = true;
        if (false !== $this->connection->fetchOne($this->connection->createQueryBuilder()->select('1')->from(self::TABLE)->setMaxResults(1)->getSQL())) {
            return false;
        }

        $this->rebuild();

        return true;
    }

    /**
     * Throws the index away and writes it again from all pages, posts and images.
     *
     * @return int the number of entries written
     */
    public function rebuild(): int
    {
        $this->built = true;
        $this->sharedBlocks = [];

        return (int) $this->connection->transactional(function (): int {
            $this->connection->executeStatement($this->connection->createQueryBuilder()->delete(self::TABLE)->getSQL());

            $count = 0;
            foreach ([Page::class, Post::class, Media::class] as $class) {
                foreach ($this->entityManager->getRepository($class)->findAll() as $entity) {
                    $this->insert($entity);
                    ++$count;
                }
            }

            return $count;
        });
    }

    /**
     * Writes the entry of one page, post or image again.
     */
    public function index(Content|Media $entity): void
    {
        if (null === $entity->getId()) {
            return;
        }

        $this->remove(self::kind($entity), $entity->getId());
        $this->insert($entity);
    }

    public function remove(string $kind, int $id): void
    {
        $this->connection->delete(self::TABLE, ['kind' => $kind, 'content_id' => $id]);
    }

    public static function kind(Content|Media $entity): string
    {
        return match (true) {
            $entity instanceof Page => SearchEntry::PAGE,
            $entity instanceof Post => SearchEntry::POST,
            $entity instanceof Media => SearchEntry::MEDIA,
            default => throw new \InvalidArgumentException(\sprintf('"%s" cannot be searched.', $entity::class)),
        };
    }

    public function reset(): void
    {
        $this->built = false;
        $this->sharedBlocks = [];
    }

    private function insert(Content|Media $entity): void
    {
        if ($entity instanceof Media) {
            $title = $entity->getOriginalName();
            $body = $entity->getAlt();
        } else {
            $title = $entity->getTitle();
            $body = $this->text($entity->getBlocks());
            if ($entity instanceof Post && null !== $entity->getExcerpt()) {
                $body = trim($entity->getExcerpt().' '.$body);
            }
        }

        $title = mb_substr($title, 0, 255);

        $this->connection->insert(self::TABLE, [
            'kind' => self::kind($entity),
            'content_id' => $entity->getId(),
            'locale' => $entity instanceof Content ? $entity->getLocale() : '',
            'title' => $title,
            'body' => $body,
            'title_folded' => mb_substr(self::fold($title), 0, 255),
            'body_folded' => self::fold($body),
            'visible' => $entity instanceof Content && ContentStatus::Published === $entity->getStatus(),
            'visible_from' => $entity instanceof Post ? $entity->getPublishedAt() : null,
        ], [
            'visible' => Types::BOOLEAN,
            'visible_from' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * The words a visitor can read in the given blocks, without any markup.
     *
     * @param array<array-key, mixed> $blocks
     */
    private function text(array $blocks, bool $followShared = true): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            if (!\is_array($block) || !\is_string($block['type'] ?? null) || !$this->registry->has($block['type'])) {
                continue;
            }

            $data = \is_array($block['data'] ?? null) ? $block['data'] : [];
            foreach ($this->registry->get($block['type'])->getFields() as $field) {
                $parts[] = $this->fieldText($field, $data[$field->name] ?? null, $followShared);
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts, static fn (string $part): bool => '' !== $part))));
    }

    private function fieldText(Field $field, mixed $value, bool $followShared): string
    {
        switch ($field->type) {
            case Field::TEXT:
            case Field::TEXTAREA:
            case Field::CODE:
                return \is_string($value) ? $value : '';
            case Field::RICH_TEXT:
                // A space in place of every tag keeps the words of neighbouring paragraphs apart.
                return \is_string($value) ? html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $value), \ENT_QUOTES | \ENT_HTML5) : '';
            case Field::BLOCKS:
                return \is_array($value) ? $this->text($value, $followShared) : '';
            case Field::ITEMS:
                $parts = [];
                foreach (\is_array($value) ? $value : [] as $item) {
                    foreach ($field->fields as $itemField) {
                        $parts[] = $this->fieldText($itemField, \is_array($item) ? ($item[$itemField->name] ?? null) : null, $followShared);
                    }
                }

                return implode(' ', $parts);
            case Field::SHARED_BLOCK:
                // The text of a shared block belongs to every page that shows it.
                return $followShared && \is_int($value) ? $this->text($this->sharedBlocks($value), false) : '';
            default:
                return '';
        }
    }

    /**
     * @return list<mixed>
     */
    private function sharedBlocks(int $id): array
    {
        return $this->sharedBlocks[$id] ??= $this->entityManager->find(SharedBlock::class, $id)?->getBlocks() ?? [];
    }
}
