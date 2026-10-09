<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\Post;
use App\Entity\Revision;
use App\Entity\User;
use App\Event\ContentDeletedEvent;
use App\Event\ContentSavedEvent;
use App\Repository\RevisionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the history of pages and posts: a revision on every save, the list
 * for the editor, and restoring an earlier state.
 */
final class RevisionManager
{
    public function __construct(
        private readonly RevisionRepository $revisions,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        #[Autowire(param: 'app.max_revisions')]
        private readonly int $maxRevisions,
    ) {
    }

    #[AsEventListener]
    public function onContentSaved(ContentSavedEvent $event): void
    {
        $content = $event->getContent();
        $user = $this->security->getUser();

        $this->entityManager->persist(new Revision(
            $this->typeOf($content),
            (int) $content->getId(),
            $content->getTitle(),
            $content->getBlocks(),
            $this->snapshotFields($content),
            $user instanceof User ? $user : null,
        ));
        $this->entityManager->flush();

        $this->prune($content);
    }

    #[AsEventListener]
    public function onContentDeleted(ContentDeletedEvent $event): void
    {
        $content = $event->getContent();
        if (null !== $content->getId()) {
            $this->revisions->deleteForContent($this->typeOf($content), $content->getId());
        }
    }

    /**
     * @return list<Revision> newest first
     */
    public function history(Content $content): array
    {
        return null === $content->getId() ? [] : $this->revisions->findForContent($this->typeOf($content), $content->getId());
    }

    /**
     * Finds a revision by id, making sure it belongs to the given content.
     */
    public function find(Content $content, int $revisionId): ?Revision
    {
        $revision = $this->revisions->find($revisionId);

        return null !== $revision
            && $revision->getContentType() === $this->typeOf($content)
            && $revision->getContentId() === $content->getId() ? $revision : null;
    }

    /**
     * Puts title, texts and blocks of a revision back onto the content.
     * The address and the published/draft status are deliberately left alone.
     * The caller saves the content, which records the restored state as a new revision.
     */
    public function apply(Revision $revision, Content $content): void
    {
        $fields = $revision->getFields();

        $content->setTitle($revision->getTitle());
        $content->setSeoTitle($fields['seoTitle'] ?? null);
        $content->setMetaDescription($fields['metaDescription'] ?? null);
        if ($content instanceof Post) {
            $content->setExcerpt($fields['excerpt'] ?? null);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshotFields(Content $content): array
    {
        $fields = [
            'seoTitle' => $content->getSeoTitle(),
            'metaDescription' => $content->getMetaDescription(),
        ];
        if ($content instanceof Post) {
            $fields['excerpt'] = $content->getExcerpt();
        }

        return $fields;
    }

    /**
     * Removes the oldest revisions beyond the configured limit.
     */
    private function prune(Content $content): void
    {
        $history = $this->history($content);
        if (\count($history) <= $this->maxRevisions) {
            return;
        }

        foreach (\array_slice($history, max(1, $this->maxRevisions)) as $outdated) {
            $this->entityManager->remove($outdated);
        }
        $this->entityManager->flush();
    }

    private function typeOf(Content $content): string
    {
        return $content instanceof Post ? Revision::TYPE_POST : Revision::TYPE_PAGE;
    }
}
