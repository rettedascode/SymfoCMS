<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\Page;
use App\Enum\ContentStatus;
use App\Event\ContentDeletedEvent;
use App\Event\ContentPublishedEvent;
use App\Event\ContentRenderEvent;
use App\Event\ContentSavedEvent;
use App\Event\ContentSavingEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The one place where pages and posts are written to the database.
 * Controllers prepare the entity; this service stores it and tells
 * extensions about it through the events in App\Event.
 */
final class ContentManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SiteUrls $siteUrls,
        private readonly RedirectManager $redirects,
    ) {
    }

    /**
     * The public address the content has in the database right now, before the
     * current edit is stored. Null when it is new or not visible to visitors.
     */
    private function storedPublicPath(Content $content): ?string
    {
        if (null === $content->getId()) {
            return null;
        }

        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($content);
        if (!\is_string($original['slug'] ?? null) || !($original['status'] ?? null) instanceof ContentStatus) {
            return null;
        }

        // A detached copy carrying the stored values answers "where was it?" without touching the real entity.
        $stored = clone $content;
        $stored->setSlug($original['slug'])->setStatus($original['status']);
        if ($stored instanceof Page) {
            $stored->setHomepage((bool) ($original['homepage'] ?? false));
        }

        return $stored->isPublic() ? $this->siteUrls->content($stored) : null;
    }

    /**
     * Stores edited content together with its (already normalized) blocks.
     *
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     */
    public function save(Content $content, array $blocks, bool $publish = false): void
    {
        $isNew = null === $content->getId();
        $wasPublished = !$isNew && ContentStatus::Published === $this->storedStatus($content);

        if ($publish) {
            $content->setStatus(ContentStatus::Published);
        }

        $saving = new ContentSavingEvent($content, $blocks, $isNew);
        $this->eventDispatcher->dispatch($saving);

        $content->setBlocks($saving->getBlocks())->setAutosave(null);
        $previousPath = $this->storedPublicPath($content);

        $this->entityManager->persist($content);
        $this->entityManager->flush();

        // Links and bookmarks to the old address keep working when the address of public content changes.
        if (null !== $previousPath && $content->isPublic()) {
            $this->redirects->addAutomatic($previousPath, $this->siteUrls->content($content));
        }

        $this->eventDispatcher->dispatch(new ContentSavedEvent($content, $isNew));

        if (!$wasPublished && ContentStatus::Published === $content->getStatus()) {
            $this->eventDispatcher->dispatch(new ContentPublishedEvent($content));
        }
    }

    public function delete(Content $content): void
    {
        $this->eventDispatcher->dispatch(new ContentDeletedEvent($content));

        $this->entityManager->remove($content);
        $this->entityManager->flush();
    }

    /**
     * Lets extensions adjust what is shown before a page or post is rendered on the public website.
     */
    public function prepareRendering(Content $content): ContentRenderEvent
    {
        $event = new ContentRenderEvent($content, $content->getBlocks());
        $this->eventDispatcher->dispatch($event);

        return $event;
    }

    /**
     * The status as it is in the database, before the current edit.
     */
    private function storedStatus(Content $content): ?ContentStatus
    {
        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($content);
        $status = $original['status'] ?? null;

        return $status instanceof ContentStatus ? $status : null;
    }
}
