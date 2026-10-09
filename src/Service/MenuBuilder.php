<?php

namespace App\Service;

use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\Post;
use App\Enum\MenuItemType;
use App\Enum\MenuLocation;
use App\Repository\MenuItemRepository;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Reads menus for the public website and stores what the menu editor sends.
 * A menu is identified by its location (main, footer) and its language.
 */
final class MenuBuilder
{
    private const MAX_ITEMS = 100;

    public function __construct(
        private readonly MenuItemRepository $menuItems,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly EntityManagerInterface $entityManager,
        private readonly SiteUrls $siteUrls,
        private readonly RequestStack $requestStack,
        private readonly LinkSanitizer $linkSanitizer,
    ) {
    }

    /**
     * Menu ready for rendering. Items pointing to content that is not public are left out.
     *
     * @param string|null $locale language of the menu; defaults to the language of the page being shown
     *
     * @return list<array{label: string, url: string, current: bool, children: list<array{label: string, url: string, current: bool, children: array{}}>}>
     */
    public function build(MenuLocation $location, ?string $locale = null): array
    {
        $request = $this->requestStack->getMainRequest();
        $currentPath = null !== $request ? $request->getBaseUrl().$request->getPathInfo() : '';
        $menu = [];

        foreach ($this->menuItems->findTree($location, $locale ?? $this->siteUrls->currentLocale()) as $item) {
            $url = $this->resolveUrl($item);
            if (null === $url) {
                continue;
            }

            $children = [];
            foreach ($item->getChildren() as $child) {
                $childUrl = $this->resolveUrl($child);
                if (null !== $childUrl) {
                    $children[] = ['label' => $child->getLabel(), 'url' => $childUrl, 'current' => $childUrl === $currentPath, 'children' => []];
                }
            }

            $menu[] = ['label' => $item->getLabel(), 'url' => $url, 'current' => $url === $currentPath, 'children' => $children];
        }

        return $menu;
    }

    /**
     * Current menu in the shape the menu editor works with.
     *
     * @return list<array<string, mixed>>
     */
    public function export(MenuLocation $location, string $locale): array
    {
        $export = static fn (MenuItem $item): array => [
            'label' => $item->getLabel(),
            'type' => $item->getType()->value,
            'page' => $item->getPage()?->getId(),
            'post' => $item->getPost()?->getId(),
            'url' => $item->getUrl() ?? '',
        ];

        $items = [];
        foreach ($this->menuItems->findTree($location, $locale) as $item) {
            $items[] = $export($item) + ['children' => array_map($export, $item->getChildren()->toArray())];
        }

        return $items;
    }

    /**
     * Replaces a whole menu with the submitted tree. Invalid entries are skipped.
     *
     * @return int number of items stored
     */
    public function replace(MenuLocation $location, string $locale, mixed $tree): int
    {
        $this->menuItems->deleteMenu($location, $locale);

        $count = 0;
        $position = 0;
        foreach (\is_array($tree) ? $tree : [] as $data) {
            $item = $this->createItem($location, $locale, $data, null, $position);
            if (null === $item || $count >= self::MAX_ITEMS) {
                continue;
            }
            ++$position;
            ++$count;

            $childPosition = 0;
            foreach (\is_array($data['children'] ?? null) ? $data['children'] : [] as $childData) {
                // Menus without dropdowns get their sub-items as regular items instead of losing them.
                $child = $location->allowsNesting()
                    ? $this->createItem($location, $locale, $childData, $item, $childPosition)
                    : $this->createItem($location, $locale, $childData, null, $position);
                if (null === $child || $count >= self::MAX_ITEMS) {
                    continue;
                }
                $location->allowsNesting() ? ++$childPosition : ++$position;
                ++$count;
            }
        }

        $this->entityManager->flush();

        return $count;
    }

    private function createItem(MenuLocation $location, string $locale, mixed $data, ?MenuItem $parent, int $position): ?MenuItem
    {
        if (!\is_array($data)) {
            return null;
        }

        $type = MenuItemType::tryFrom(\is_string($data['type'] ?? null) ? $data['type'] : '');
        $label = \is_string($data['label'] ?? null) ? trim($data['label']) : '';
        $item = (new MenuItem())->setLocation($location)->setLocale($locale)->setParent($parent)->setPosition($position);

        switch ($type) {
            case MenuItemType::Page:
                $page = is_numeric($data['page'] ?? null) ? $this->pages->find((int) $data['page']) : null;
                // A menu only links to content of its own language.
                if (!$page instanceof Page || $page->getLocale() !== $locale) {
                    return null;
                }
                $item->setType(MenuItemType::Page)->setPage($page)->setLabel('' !== $label ? $label : $page->getTitle());
                break;

            case MenuItemType::Post:
                $post = is_numeric($data['post'] ?? null) ? $this->posts->find((int) $data['post']) : null;
                if (!$post instanceof Post || $post->getLocale() !== $locale) {
                    return null;
                }
                $item->setType(MenuItemType::Post)->setPost($post)->setLabel('' !== $label ? $label : $post->getTitle());
                break;

            case MenuItemType::Url:
                $url = $this->linkSanitizer->sanitize($data['url'] ?? '');
                if ('' === $url || '' === $label) {
                    return null;
                }
                $item->setType(MenuItemType::Url)->setUrl($url)->setLabel($label);
                break;

            default:
                return null;
        }

        $this->entityManager->persist($item);

        return $item;
    }

    private function resolveUrl(MenuItem $item): ?string
    {
        if (MenuItemType::Url === $item->getType()) {
            return $item->getUrl();
        }

        $content = MenuItemType::Page === $item->getType() ? $item->getPage() : $item->getPost();

        return null !== $content && $content->isPublic() ? $this->siteUrls->content($content) : null;
    }
}
