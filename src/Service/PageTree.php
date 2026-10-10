<?php

namespace App\Service;

use App\DragDrop\InvalidMoveException;
use App\DragDrop\TreeMover;
use App\Entity\Page;
use App\Repository\PageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The hierarchy of pages: a page can have child pages, and the address of a
 * child starts with the address of its parent ("/about/team").
 *
 * Every language has its own tree. The homepage always stays on the top
 * level and has no children, because its address is "/".
 */
final class PageTree
{
    /** Deepest level a page can be on; the top level is 1. */
    public const MAX_DEPTH = 4;

    public const HOMEPAGE = 'page.tree.error.homepage';
    public const OTHER_LANGUAGE = 'page.tree.error.language';

    public function __construct(
        private readonly PageRepository $pages,
        private readonly EntityManagerInterface $entityManager,
        private readonly TreeMover $mover,
        private readonly SiteUrls $siteUrls,
        private readonly RedirectManager $redirects,
    ) {
    }

    /**
     * All pages of a language as a tree, siblings in their order.
     *
     * @return list<array{page: Page, children: list<array<string, mixed>>}>
     */
    public function tree(string $locale): array
    {
        $byParent = [];
        foreach ($this->pages->findBy(['locale' => $locale], ['position' => 'ASC', 'title' => 'ASC']) as $page) {
            $byParent[$page->getParent()?->getId() ?? 0][] = $page;
        }

        $build = static function (int $parentId) use (&$build, $byParent): array {
            return array_map(
                static fn (Page $page): array => ['page' => $page, 'children' => $build((int) $page->getId())],
                $byParent[$parentId] ?? [],
            );
        };

        return $build(0);
    }

    /**
     * @return list<Page>
     */
    public function children(?Page $parent, string $locale): array
    {
        return $this->pages->findBy(['parent' => $parent, 'locale' => $locale], ['position' => 'ASC', 'title' => 'ASC']);
    }

    /**
     * Every page below the given one, at any depth.
     *
     * @return list<Page>
     */
    public function descendants(Page $page): array
    {
        $descendants = [];
        foreach ($this->children($page, $page->getLocale()) as $child) {
            $descendants[] = $child;
            array_push($descendants, ...$this->descendants($child));
        }

        return $descendants;
    }

    /**
     * Number of levels of the subtree that starts at the page: 1 for a page without children.
     */
    public function height(Page $page): int
    {
        $height = 1;
        foreach ($this->children($page, $page->getLocale()) as $child) {
            $height = max($height, 1 + $this->height($child));
        }

        return $height;
    }

    /**
     * What a move would do to the addresses of published pages - the page itself and everything below it.
     * Nothing is changed.
     *
     * @return list<array{id: int|null, title: string, from: string, to: string}>
     */
    public function addressChanges(Page $page, ?Page $newParent): array
    {
        $oldPrefix = $page->getPath();
        $newPrefix = (null !== $newParent ? $newParent->getPath().'/' : '').$page->getSlug();
        if ($oldPrefix === $newPrefix) {
            return [];
        }

        $changes = [];
        foreach ([$page, ...$this->descendants($page)] as $affected) {
            if ($affected->isPublic() && !$affected->isHomepage()) {
                $old = $this->siteUrls->content($affected);
                $suffix = substr($affected->getPath(), \strlen($oldPrefix));
                $new = $this->siteUrls->generate('app_page', ['slug' => $newPrefix.$suffix], $affected->getLocale());
                $changes[] = ['id' => $affected->getId(), 'title' => $affected->getTitle(), 'from' => $old, 'to' => $new];
            }
        }

        return $changes;
    }

    /**
     * Moves a page below another one (or to the top level) and to a place among its new siblings.
     * Old addresses of published pages get a redirect to the new ones.
     *
     * @return list<array{id: int|null, title: string, from: string, to: string}> the addresses that changed
     *
     * @throws InvalidMoveException when the structure does not allow the move
     */
    public function move(Page $page, ?Page $newParent, int $position): array
    {
        $this->assertAllowed($page, $newParent);
        $changes = $this->addressChanges($page, $newParent);
        $this->mover->move(
            $page,
            $newParent,
            $position,
            $this->children($newParent, $page->getLocale()),
            $this->children($page->getParent(), $page->getLocale()),
            self::MAX_DEPTH,
            $this->height($page),
        );
        $this->entityManager->flush();

        foreach ($changes as $change) {
            $this->redirects->addAutomatic($change['from'], $change['to']);
        }

        return $changes;
    }

    /**
     * Checks a move without making it.
     *
     * @throws InvalidMoveException
     */
    public function assertAllowed(Page $page, ?Page $newParent): void
    {
        if (null !== $newParent && $newParent->getLocale() !== $page->getLocale()) {
            throw new InvalidMoveException(self::OTHER_LANGUAGE);
        }
        // The homepage lives at "/": it is nobody's child and nobody's parent.
        if (null !== $newParent && ($page->isHomepage() || $newParent->isHomepage())) {
            throw new InvalidMoveException(self::HOMEPAGE);
        }

        $depth = 1;
        for ($ancestor = $newParent; null !== $ancestor; $ancestor = $ancestor->getParent()) {
            if ($ancestor === $page) {
                throw new InvalidMoveException(InvalidMoveException::INTO_ITSELF);
            }
            ++$depth;
        }
        if ($depth + $this->height($page) - 1 > self::MAX_DEPTH) {
            throw new InvalidMoveException(InvalidMoveException::TOO_DEEP);
        }
    }

    /**
     * Before a page is deleted its children move up one level, so they do not lose their place in the tree.
     * Their addresses change, which is covered by redirects.
     */
    public function releaseChildren(Page $page): void
    {
        $parent = $page->getParent();
        $changes = [];
        foreach ($this->children($page, $page->getLocale()) as $child) {
            array_push($changes, ...$this->addressChanges($child, $parent));
            $child->setParent($parent);
        }
        $this->entityManager->flush();

        foreach ($changes as $change) {
            $this->redirects->addAutomatic($change['from'], $change['to']);
        }
    }
}
