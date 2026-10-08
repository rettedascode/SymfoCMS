<?php

namespace App\Controller\Admin;

use App\Entity\Page;
use App\Entity\Post;
use App\Enum\MenuLocation;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use App\Security\Voter\AdminAreaVoter;
use App\Service\ContentEditor;
use App\Service\MenuBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/menus', name: 'admin_menu_')]
#[IsGranted(AdminAreaVoter::MANAGE_MENUS)]
final class MenuController extends AdminController
{
    public function __construct(private readonly MenuBuilder $menuBuilder)
    {
    }

    #[Route('/{location}', name: 'index', methods: ['GET'], defaults: ['location' => 'main'])]
    public function index(MenuLocation $location, PageRepository $pages, PostRepository $posts, ContentEditor $editor, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $choice = static fn (Page|Post $content): array => ['id' => $content->getId(), 'title' => $content->getTitle()];

        return $this->render('admin/menu/index.html.twig', [
            'location' => $location,
            'locations' => MenuLocation::cases(),
            'config' => [
                'items' => $this->menuBuilder->export($location),
                'pages' => array_map($choice, $pages->findBy([], ['title' => 'ASC'])),
                'posts' => array_map($choice, $posts->findBy([], ['title' => 'ASC'])),
                'allowNesting' => $location->allowsNesting(),
                'saveUrl' => $this->generateUrl('admin_menu_save', ['location' => $location->value]),
                'blogUrl' => $this->generateUrl('app_blog'),
                'csrf' => $csrfTokenManager->getToken(self::CSRF_ID)->getValue(),
                'i18n' => $editor->translations('menu.js.'),
            ],
        ]);
    }

    #[Route('/{location}', name: 'save', methods: ['POST'])]
    public function save(Request $request, MenuLocation $location): JsonResponse
    {
        $this->requireCsrfToken($request);

        $payload = json_decode($request->getContent(), true);
        $this->menuBuilder->replace($location, \is_array($payload) ? ($payload['items'] ?? []) : []);

        // Send back what was actually stored so the editor shows exactly that.
        return $this->json(['items' => $this->menuBuilder->export($location)]);
    }
}
