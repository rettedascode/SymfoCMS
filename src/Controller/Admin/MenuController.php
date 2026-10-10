<?php

namespace App\Controller\Admin;

use App\Entity\Post;
use App\Enum\MenuLocation;
use App\Repository\PostRepository;
use App\Security\Voter\AdminAreaVoter;
use App\Service\ContentEditor;
use App\Service\ContentLocales;
use App\Service\MenuBuilder;
use App\Service\PageTree;
use App\Service\SiteUrls;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Each language has its own main and footer navigation; "?locale=de" selects the language.
 */
#[Route('/admin/menus', name: 'admin_menu_')]
#[IsGranted(AdminAreaVoter::MANAGE_MENUS)]
final class MenuController extends AdminController
{
    public function __construct(
        private readonly MenuBuilder $menuBuilder,
        private readonly ContentLocales $locales,
    ) {
    }

    #[Route('/{location}', name: 'index', methods: ['GET'], defaults: ['location' => 'main'])]
    public function index(Request $request, MenuLocation $location, PageTree $pageTree, PostRepository $posts, ContentEditor $editor, SiteUrls $siteUrls, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $locale = $this->locales->fromRequest($request);
        $choice = static fn (Post $post): array => ['id' => $post->getId(), 'title' => $post->getTitle()];

        return $this->render('admin/menu/index.html.twig', [
            'location' => $location,
            'locations' => MenuLocation::cases(),
            'locale' => $locale,
            'config' => [
                'items' => $this->menuBuilder->export($location, $locale),
                'pages' => $this->pageChoices($pageTree->tree($locale)),
                'posts' => array_map($choice, $posts->findBy(['locale' => $locale], ['title' => 'ASC'])),
                'allowNesting' => $location->allowsNesting(),
                'saveUrl' => $this->generateUrl('admin_menu_save', ['location' => $location->value, 'locale' => $locale]),
                'blogUrl' => $siteUrls->generate('app_blog', [], $locale),
                'csrf' => $csrfTokenManager->getToken(self::CSRF_ID)->getValue(),
                'i18n' => $editor->translations('menu.js.'),
            ],
        ]);
    }

    #[Route('/{location}', name: 'save', methods: ['POST'])]
    public function save(Request $request, MenuLocation $location): JsonResponse
    {
        $this->requireCsrfToken($request);
        $locale = $this->locales->fromRequest($request);

        $payload = json_decode($request->getContent(), true);
        $this->menuBuilder->replace($location, $locale, \is_array($payload) ? ($payload['items'] ?? []) : []);

        // Send back what was actually stored so the editor shows exactly that.
        return $this->json(['items' => $this->menuBuilder->export($location, $locale)]);
    }

    /**
     * The pages in the order of the page tree, each with the level it is on, for the page tree next to the menu.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array{id: int|null, title: string, depth: int}>
     */
    private function pageChoices(array $nodes, int $depth = 0): array
    {
        $choices = [];
        foreach ($nodes as $node) {
            $choices[] = ['id' => $node['page']->getId(), 'title' => $node['page']->getTitle(), 'depth' => $depth];
            array_push($choices, ...$this->pageChoices($node['children'], $depth + 1));
        }

        return $choices;
    }
}
