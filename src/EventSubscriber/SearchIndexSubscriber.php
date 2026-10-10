<?php

namespace App\EventSubscriber;

use App\Entity\Content;
use App\Entity\Media;
use App\Entity\SharedBlock;
use App\Service\SearchIndexer;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Updates the search index whenever a page, post or image is stored or
 * deleted - no matter whether that happens in the editor, in the fixtures, in
 * an import or in the code of an extension.
 *
 * Changes are collected while Doctrine writes and applied once it is done.
 */
#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::preRemove)]
#[AsDoctrineListener(Events::postFlush)]
#[AsDoctrineListener(Events::onClear)]
final class SearchIndexSubscriber implements ResetInterface
{
    /** @var array<int, Content|Media> */
    private array $changed = [];

    /** @var list<array{string, int}> kind and id of deleted content */
    private array $removed = [];

    private bool $rebuild = false;

    public function __construct(private readonly SearchIndexer $indexer)
    {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if (($entity instanceof Content || $entity instanceof Media) && null !== $entity->getId()) {
            $this->removed[] = [SearchIndexer::kind($entity), $entity->getId()];
            unset($this->changed[spl_object_id($entity)]);
        } elseif ($entity instanceof SharedBlock) {
            $this->rebuild = true;
        }
    }

    public function postFlush(): void
    {
        if ([] === $this->changed && [] === $this->removed && !$this->rebuild) {
            return;
        }

        [$changed, $removed, $rebuild] = [$this->changed, $this->removed, $this->rebuild];
        $this->reset();

        if ($rebuild) {
            $this->indexer->rebuild();

            return;
        }

        // An index that was never built is written completely, which includes the changes at hand.
        if ($this->indexer->ensureBuilt()) {
            return;
        }

        foreach ($removed as [$kind, $id]) {
            $this->indexer->remove($kind, $id);
        }

        foreach ($changed as $entity) {
            $this->indexer->index($entity);
        }
    }

    public function onClear(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->changed = [];
        $this->removed = [];
        $this->rebuild = false;
    }

    private function collect(object $entity): void
    {
        if ($entity instanceof Content || $entity instanceof Media) {
            $this->changed[spl_object_id($entity)] = $entity;
        } elseif ($entity instanceof SharedBlock) {
            // Its text is part of every page that shows it.
            $this->rebuild = true;
        }
    }
}
