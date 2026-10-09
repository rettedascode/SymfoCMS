<?php

namespace App\Controller\Admin;

use App\Block\BlockNormalizer;
use App\Entity\Page;
use App\Form\NewContentType;
use App\Form\PageType;
use App\Repository\PageRepository;
use App\Security\Voter\PageVoter;
use App\Service\ContentEditor;
use App\Service\ContentManager;
use App\Service\SlugGenerator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/pages', name: 'admin_page_')]
#[IsGranted(PageVoter::MANAGE)]
final class PageController extends AdminController
{
    public function __construct(
        private readonly ContentManager $contentManager,
        private readonly PageRepository $pages,
        private readonly ContentEditor $editor,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/page/index.html.twig', [
            'pages' => $this->pages->findBy([], ['homepage' => 'DESC', 'title' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugGenerator): Response
    {
        $form = $this->createForm(NewContentType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $title = (string) $form->get('title')->getData();
            $page = (new Page())
                ->setTitle($title)
                ->setSlug($slugGenerator->unique($title, Page::class));

            $this->contentManager->save($page, []);

            return $this->redirectToRoute('admin_page_edit', ['id' => $page->getId()]);
        }

        return $this->render('admin/content/new.html.twig', [
            'form' => $form,
            'heading' => 'page.new',
            'intro' => 'page.new_intro',
            'back' => $this->generateUrl('admin_page_index'),
        ]);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Page $page, BlockNormalizer $normalizer): Response
    {
        $form = $this->createForm(PageType::class, $page);
        $form->handleRequest($request);
        $blocks = $page->getBlocks();

        if ($form->isSubmitted()) {
            $blocks = $normalizer->normalizeJson($form->get('blocks')->getData());

            if ($form->isValid()) {
                if ($page->isHomepage()) {
                    $this->pages->unsetOtherHomepages($page);
                }

                $this->contentManager->save($page, $blocks, publish: $request->request->has('publish'));
                $this->addFlash('success', $page->isPublic() ? 'flash.page_published' : 'flash.draft_saved');

                return $this->redirectToRoute('admin_page_edit', ['id' => $page->getId()]);
            }
        }

        return $this->render('admin/page/edit.html.twig', [
            'form' => $form,
            'page' => $page,
            'editor_config' => $this->editor->config($page, $blocks, [
                'autosave' => $this->generateUrl('admin_page_autosave', ['id' => $page->getId()]),
                'discard' => $this->generateUrl('admin_page_discard_autosave', ['id' => $page->getId()]),
            ], 'page'),
        ]);
    }

    #[Route('/{id<\d+>}/autosave', name: 'autosave', methods: ['POST'])]
    public function autosave(Request $request, Page $page): JsonResponse
    {
        $this->requireCsrfToken($request);

        return $this->json(['savedAt' => $this->editor->autosave($page, $request)->format(\DATE_ATOM)]);
    }

    #[Route('/{id<\d+>}/autosave/discard', name: 'discard_autosave', methods: ['POST'])]
    public function discardAutosave(Request $request, Page $page): JsonResponse
    {
        $this->requireCsrfToken($request);
        $this->editor->discardAutosave($page);

        return $this->json(['ok' => true]);
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Page $page): Response
    {
        $this->requireCsrfToken($request);

        $this->contentManager->delete($page);
        $this->addFlash('success', 'flash.page_deleted');

        return $this->redirectToRoute('admin_page_index');
    }
}
