<?php

namespace App\Controller\Admin;

use App\Entity\Page;
use App\Form\NewContentType;
use App\Form\PageType;
use App\Repository\PageRepository;
use App\Security\Voter\PageVoter;
use App\Service\ContentEditor;
use App\Service\ContentLocales;
use App\Service\ContentManager;
use App\Service\ContentTranslator;
use App\Service\RevisionManager;
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
        private readonly ContentLocales $locales,
        private readonly ContentTranslator $translator,
        private readonly RevisionManager $revisions,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $locale = $this->locales->fromRequest($request);

        return $this->render('admin/page/index.html.twig', [
            'pages' => $this->pages->findBy(['locale' => $locale], ['homepage' => 'DESC', 'title' => 'ASC']),
            'locale' => $locale,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugGenerator): Response
    {
        $locale = $this->locales->fromRequest($request);
        $form = $this->createForm(NewContentType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $title = (string) $form->get('title')->getData();
            $page = (new Page())
                ->setLocale($locale)
                ->setTitle($title)
                ->setSlug($slugGenerator->unique($title, Page::class, $locale));

            $this->contentManager->save($page, []);

            return $this->redirectToRoute('admin_page_edit', ['id' => $page->getId()]);
        }

        return $this->render('admin/content/new.html.twig', [
            'form' => $form,
            'heading' => 'page.new',
            'intro' => 'page.new_intro',
            'locale' => $locale,
            'back' => $this->generateUrl('admin_page_index', ['locale' => $locale]),
        ]);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Page $page): Response
    {
        $form = $this->createForm(PageType::class, $page);
        $form->handleRequest($request);
        $blocks = $page->getBlocks();
        $errors = [];

        if ($form->isSubmitted()) {
            [$blocks, $errors] = $this->editor->submittedBlocks($form);

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
            'translations' => $this->translator->overview($page),
            'revisions' => $this->revisions->history($page),
            'editor_config' => $this->editor->config($page, $blocks, [
                'autosave' => $this->generateUrl('admin_page_autosave', ['id' => $page->getId()]),
                'discard' => $this->generateUrl('admin_page_discard_autosave', ['id' => $page->getId()]),
            ], 'page', $errors),
        ]);
    }

    /**
     * Creates the version of a page in another language, starting as a copy, and opens it.
     */
    #[Route('/{id<\d+>}/translate/{locale}', name: 'translate', methods: ['POST'])]
    public function translate(Request $request, Page $page, string $locale): Response
    {
        $this->requireCsrfToken($request);

        if (!$this->locales->isEnabled($locale) || $locale === $page->getLocale()) {
            throw $this->createNotFoundException();
        }

        $isNew = !isset($this->translator->versions($page)[$locale]);
        $translation = $this->translator->translate($page, $locale);
        if ($isNew) {
            $this->addFlash('success', 'flash.translation_created');
        }

        return $this->redirectToRoute('admin_page_edit', ['id' => $translation->getId()]);
    }

    /**
     * Shows an earlier version of the page in the public theme.
     */
    #[Route('/{id<\d+>}/revisions/{revision<\d+>}', name: 'revision', methods: ['GET'])]
    public function revision(Page $page, int $revision): Response
    {
        $snapshot = $this->revisions->find($page, $revision) ?? throw $this->createNotFoundException();

        return $this->render('site/preview.html.twig', [
            'title' => $snapshot->getTitle(),
            'blocks' => $snapshot->getBlocks(),
            'kind' => 'page',
            'revision' => $snapshot,
        ]);
    }

    /**
     * Brings an earlier version back. Saving it records the restored state as the newest version.
     */
    #[Route('/{id<\d+>}/revisions/{revision<\d+>}/restore', name: 'revision_restore', methods: ['POST'])]
    public function restoreRevision(Request $request, Page $page, int $revision): Response
    {
        $this->requireCsrfToken($request);
        $snapshot = $this->revisions->find($page, $revision) ?? throw $this->createNotFoundException();

        $this->revisions->apply($snapshot, $page);
        $this->contentManager->save($page, $snapshot->getBlocks());
        $this->addFlash('success', 'flash.revision_restored');

        return $this->redirectToRoute('admin_page_edit', ['id' => $page->getId()]);
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
        $locale = $page->getLocale();

        $this->contentManager->delete($page);
        $this->addFlash('success', 'flash.page_deleted');

        return $this->redirectToRoute('admin_page_index', $this->locales->isDefault($locale) ? [] : ['locale' => $locale]);
    }
}
