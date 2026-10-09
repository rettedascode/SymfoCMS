<?php

namespace App\Service;

use App\Block\BlockRegistry;
use App\Block\Field;
use App\Entity\Content;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\SharedBlock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Answers "where is this shared block used?" by looking through the blocks of all pages and posts.
 */
final class SharedBlockUsage
{
    /** @var array<int, list<Content>>|null shared block id => content using it */
    private ?array $usage = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BlockRegistry $registry,
    ) {
    }

    /**
     * @return list<Content> pages and posts that show the shared block
     */
    public function find(SharedBlock $sharedBlock): array
    {
        return $this->all()[(int) $sharedBlock->getId()] ?? [];
    }

    public function count(SharedBlock $sharedBlock): int
    {
        return \count($this->find($sharedBlock));
    }

    /**
     * @return array<int, list<Content>>
     */
    private function all(): array
    {
        if (null !== $this->usage) {
            return $this->usage;
        }

        $this->usage = [];
        foreach ([Page::class, Post::class] as $class) {
            foreach ($this->entityManager->getRepository($class)->findBy([], ['title' => 'ASC']) as $content) {
                foreach (array_unique($this->referencedIds($content->getBlocks())) as $id) {
                    $this->usage[$id][] = $content;
                }
            }
        }

        return $this->usage;
    }

    /**
     * @param array<array-key, mixed> $blocks
     *
     * @return list<int>
     */
    private function referencedIds(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (!\is_array($block) || !\is_string($block['type'] ?? null) || !$this->registry->has($block['type'])) {
                continue;
            }

            foreach ($this->registry->get($block['type'])->getFields() as $field) {
                $value = $block['data'][$field->name] ?? null;
                if (Field::SHARED_BLOCK === $field->type && \is_int($value)) {
                    $ids[] = $value;
                } elseif (Field::BLOCKS === $field->type && \is_array($value)) {
                    $ids = [...$ids, ...$this->referencedIds($value)];
                }
            }
        }

        return $ids;
    }
}
