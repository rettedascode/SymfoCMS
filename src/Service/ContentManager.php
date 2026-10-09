<?php

namespace App\Service;

use App\Entity\Content;
use App\Enum\ContentStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place where pages and posts are written to the database.
 * Controllers prepare the entity; this service stores it.
 */
final class ContentManager
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Stores edited content together with its (already normalized) blocks.
     *
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     */
    public function save(Content $content, array $blocks, bool $publish = false): void
    {
        if ($publish) {
            $content->setStatus(ContentStatus::Published);
        }

        $content->setBlocks($blocks)->setAutosave(null);

        $this->entityManager->persist($content);
        $this->entityManager->flush();
    }

    public function delete(Content $content): void
    {
        $this->entityManager->remove($content);
        $this->entityManager->flush();
    }
}
