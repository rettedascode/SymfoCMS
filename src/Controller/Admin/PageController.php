<?php

namespace App\Controller\Admin;

use App\DragDrop\InvalidMoveException;
use App\DragDrop\MoveRequest;
use App\Entity\Page;
use App\Form\NewContentType;
use App\Form\PageType;
use App\Repository\PageRepository;
use App\Security\Voter\PageVoter;
use App\Service\ContentEditor;
use App\Service\ContentLocales;
use App\Service\ContentManager;
use App\Service\ContentTranslator;
use App\Service\PageTemplates;
use App\Service\PageTree;
use App\Service\RevisionManager;
use App\Service\SiteUrls;
use App\Service\SlugGenerator;
use App\Workflow\ReviewWorkflow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly ReviewWorkflow $review,
        private readonly PageTree $tree,
        private readonly SiteUrls $siteUrls,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $locale = $this->locales->fromRequest($request);

        return $this->render('admin/page/index.html.twig', [
            'tree' => $this->tree->tree($locale),
            'locale' => $locale,
            'max_depth' => PageTree::MAX_DEPTH,
        ]);
    }

    /**
     * A page was dragged to another place in the tree: below another page, to the top level, or among its siblings.
     *
     * When that changes the address of published pages, nothing happens until the person has confirmed it;
     * the answer 409 lists the addresses. With "confirmed": true the move is made and the old addresses redirect.
     */
    #[Route('/move', name: 'move', methods: ['POST'])]
    public function move(Request $request, TranslatorInterface $translator): JsonResponse
    {
        $this->requireCsrfToken($request);
        $move = MoveRequest::fromRequest($request);

        $page = $this->pages->find($move->id) ?? throw $this->createNotFoundException();
        $parent = null !== $move->parent ? $this->pages->find($move->parent) : null;
        if (null !== $move->parent && null === $parent) {
            return $this->json(['error' => $translator->trans(InvalidMoveException::WRONG_PARENT)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $changes = $this->tree->addressChanges($page, $parent);
        $confirmed = true === (json_decode($request->getContent(), true)['confirmed'] ?? false);

        try {
            if ([] !== $changes && !$confirmed) {
                // Dry run: would the move be allowed at all? Only then is it worth asking.
                $this->tree->assertAllowed($page, $parent);

                return $this->json([
                    'confirm' => $translator->trans('page.tree.confirm', ['%count%' => \count($changes)]),
                    'changes' => $changes,
                ], Response::HTTP_CONFLICT);
            }

            $changes = $this->tree->move($page, $parent, $move->position);
        } catch (InvalidMoveException $exception) {
            return $this->json(['error' => $translator->trans($exception->getMessage())], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The addresses shown in the tree, for the page and everything below it.
        $paths = [];
        foreach ([$page, ...$this->tree->descendants($page)] as $affected) {
            $paths[(int) $affected->getId()] = $this->siteUrls->content($affected);
        }

        return $this->json(['paths' => $paths, 'redirects' => \count($changes)]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugGenerator, PageTemplates $templates): Response
    {
        $locale = $this->locales->fromRequest($request);
        $form = $this->createForm(NewContentType::class, null, ['templates' => $templates->choices()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $title = (string) $form->get('title')->getData();
            $page = (new Page())
                ->setLocale($locale)
                ->setTitle($title)
                ->setSlug($slugGenerator->unique($title, Page::class, $locale));

            // The template only provides the first blocks; from here on the page is edited freely.
            $this->contentManager->save($page, $templates->blocks((string) $form->get('template')->getData(), $locale));

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
        $original = $page->getStatus();
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

                if (!$this->review->handle($page, $original, $request)) {
                    throw $this->createAccessDeniedException('This change of status is not allowed.');
                }

                $this->contentManager->save($page, $blocks);
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

        // Child pages are not deleted along: they move up one level.
        $this->tree->releaseChildren($page);
        $this->contentManager->delete($page);
        $this->addFlash('success', 'flash.page_deleted');

        return $this->redirectToRoute('admin_page_index', $this->locales->isDefault($locale) ? [] : ['locale' => $locale]);
    }
}
