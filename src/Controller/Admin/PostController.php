<?php

namespace App\Controller\Admin;

use App\Entity\Post;
use App\Enum\ContentStatus;
use App\Form\NewContentType;
use App\Form\PostType;
use App\Repository\PostRepository;
use App\Security\Voter\PostVoter;
use App\Service\ContentEditor;
use App\Service\ContentLocales;
use App\Service\ContentManager;
use App\Service\ContentTranslator;
use App\Service\RevisionManager;
use App\Service\SlugGenerator;
use App\Workflow\ReviewWorkflow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/posts', name: 'admin_post_')]
final class PostController extends AdminController
{
    public function __construct(
        private readonly ContentManager $contentManager,
        private readonly PostRepository $posts,
        private readonly ContentEditor $editor,
        private readonly ContentLocales $locales,
        private readonly ContentTranslator $translator,
        private readonly RevisionManager $revisions,
        private readonly ReviewWorkflow $review,
    ) {
    }

    /**
     * The statuses offered in the settings of a post. "In review" only appears where reviews are used.
     *
     * @return list<ContentStatus>
     */
    private function statusChoices(Post $post): array
    {
        // Authors use the buttons of the editor instead: they cannot publish themselves.
        if ($this->review->isReviewRequired() && !$this->isGranted(PostVoter::MANAGE_ALL)) {
            return [];
        }

        return array_values(array_filter(
            $this->review->reachableStatuses($post),
            fn (ContentStatus $status): bool => ContentStatus::Review !== $status || $this->review->isReviewRequired() || ContentStatus::Review === $post->getStatus(),
        ));
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted(PostVoter::CREATE)]
    public function index(Request $request): Response
    {
        $locale = $this->locales->fromRequest($request);
        // Authors only see what they wrote themselves.
        $author = $this->isGranted(PostVoter::MANAGE_ALL) ? null : $this->currentUser();

        $posts = $this->posts->findForAdmin($locale, $author);

        return $this->render('admin/post/index.html.twig', [
            'posts' => $posts,
            'waiting' => \count(array_filter($posts, static fn (Post $post): bool => ContentStatus::Review === $post->getStatus())),
            'can_review' => $this->isGranted(PostVoter::MANAGE_ALL),
            'locale' => $locale,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted(PostVoter::CREATE)]
    public function new(Request $request, SlugGenerator $slugGenerator): Response
    {
        $locale = $this->locales->fromRequest($request);
        $form = $this->createForm(NewContentType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $title = (string) $form->get('title')->getData();
            $post = (new Post())
                ->setLocale($locale)
                ->setTitle($title)
                ->setSlug($slugGenerator->unique($title, Post::class, $locale))
                ->setAuthor($this->currentUser());

            $this->contentManager->save($post, []);

            return $this->redirectToRoute('admin_post_edit', ['id' => $post->getId()]);
        }

        return $this->render('admin/content/new.html.twig', [
            'form' => $form,
            'heading' => 'post.new',
            'intro' => 'post.new_intro',
            'locale' => $locale,
            'back' => $this->generateUrl('admin_post_index', ['locale' => $locale]),
        ]);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET', 'POST'])]
    #[IsGranted(PostVoter::EDIT, subject: 'post')]
    public function edit(Request $request, Post $post): Response
    {
        $original = $post->getStatus();
        $form = $this->createForm(PostType::class, $post, [
            'can_change_author' => $this->isGranted(PostVoter::MANAGE_ALL),
            'statuses' => $this->statusChoices($post),
        ]);
        $form->handleRequest($request);
        $blocks = $post->getBlocks();
        $errors = [];

        if ($form->isSubmitted()) {
            [$blocks, $errors] = $this->editor->submittedBlocks($form);

            if ($form->isValid()) {
                if (!$this->review->handle($post, $original, $request)) {
                    throw $this->createAccessDeniedException('This change of status is not allowed.');
                }
                // Publishing without a date means "now"; a date in the future schedules the post.
                if (ContentStatus::Published === $post->getStatus() && null === $post->getPublishedAt()) {
                    $post->setPublishedAt(new \DateTimeImmutable());
                }

                $this->contentManager->save($post, $blocks);
                $this->addFlash('success', match (true) {
                    ContentStatus::Review === $post->getStatus() && ContentStatus::Review !== $original => 'flash.post_submitted',
                    ContentStatus::Review === $original && ContentStatus::Draft === $post->getStatus() => $request->request->has('reject') ? 'flash.post_sent_back' : 'flash.post_withdrawn',
                    $post->isScheduled() => 'flash.post_scheduled',
                    $post->isPublic() => 'flash.post_published',
                    default => 'flash.draft_saved',
                });

                return $this->redirectToRoute('admin_post_edit', ['id' => $post->getId()]);
            }
        }

        return $this->render('admin/post/edit.html.twig', [
            'form' => $form,
            'post' => $post,
            'translations' => $this->translator->overview($post),
            'revisions' => $this->revisions->history($post),
            'review' => [
                'required' => $this->review->isReviewRequired(),
                'can_submit' => $this->review->can($post, ReviewWorkflow::SUBMIT) && !$this->review->can($post, ReviewWorkflow::PUBLISH),
                'can_publish' => $this->review->can($post, ReviewWorkflow::PUBLISH),
                'can_withdraw' => $this->review->can($post, ReviewWorkflow::WITHDRAW) && !$this->review->can($post, ReviewWorkflow::APPROVE),
                'can_decide' => $this->review->can($post, ReviewWorkflow::APPROVE),
            ],
            'editor_config' => $this->editor->config($post, $blocks, [
                'autosave' => $this->generateUrl('admin_post_autosave', ['id' => $post->getId()]),
                'discard' => $this->generateUrl('admin_post_discard_autosave', ['id' => $post->getId()]),
            ], 'post', $errors),
        ]);
    }

    /**
     * Creates the version of a post in another language, starting as a copy, and opens it.
     */
    #[Route('/{id<\d+>}/translate/{locale}', name: 'translate', methods: ['POST'])]
    #[IsGranted(PostVoter::EDIT, subject: 'post')]
    public function translate(Request $request, Post $post, string $locale): Response
    {
        $this->requireCsrfToken($request);

        if (!$this->locales->isEnabled($locale) || $locale === $post->getLocale()) {
            throw $this->createNotFoundException();
        }

        $existing = $this->translator->versions($post)[$locale] ?? null;
        if (null !== $existing) {
            // Somebody else may own the existing version: the usual permission check applies.
            $this->denyAccessUnlessGranted(PostVoter::EDIT, $existing);

            return $this->redirectToRoute('admin_post_edit', ['id' => $existing->getId()]);
        }

        $translation = $this->translator->translate($post, $locale);
        $this->addFlash('success', 'flash.translation_created');

        return $this->redirectToRoute('admin_post_edit', ['id' => $translation->getId()]);
    }

    /**
     * Shows an earlier version of the post in the public theme.
     */
    #[Route('/{id<\d+>}/revisions/{revision<\d+>}', name: 'revision', methods: ['GET'])]
    #[IsGranted(PostVoter::EDIT, subject: 'post')]
    public function revision(Post $post, int $revision): Response
    {
        $snapshot = $this->revisions->find($post, $revision) ?? throw $this->createNotFoundException();

        return $this->render('site/preview.html.twig', [
            'title' => $snapshot->getTitle(),
            'blocks' => $snapshot->getBlocks(),
            'kind' => 'post',
            'revision' => $snapshot,
        ]);
    }

    /**
     * Brings an earlier version back. Saving it records the restored state as the newest version.
     */
    #[Route('/{id<\d+>}/revisions/{revision<\d+>}/restore', name: 'revision_restore', methods: ['POST'])]
    #[IsGranted(PostVoter::EDIT, subject: 'post')]
    public function restoreRevision(Request $request, Post $post, int $revision): Response
    {
        $this->requireCsrfToken($request);
        $snapshot = $this->revisions->find($post, $revision) ?? throw $this->createNotFoundException();

        $this->revisions->apply($snapshot, $post);
        $this->contentManager->save($post, $snapshot->getBlocks());
        $this->addFlash('success', 'flash.revision_restored');

        return $this->redirectToRoute('admin_post_edit', ['id' => $post->getId()]);
    }

    #[Route('/{id<\d+>}/autosave', name: 'autosave', methods: ['POST'])]
    #[IsGranted(PostVoter::EDIT, subject: 'post')]
    public function autosave(Request $request, Post $post): JsonResponse
    {
        $this->requireCsrfToken($request);

        return $this->json(['savedAt' => $this->editor->autosave($post, $request)->format(\DATE_ATOM)]);
    }

    #[Route('/{id<\d+>}/autosave/discard', name: 'discard_autosave', methods: ['POST'])]
    #[IsGranted(PostVoter::EDIT, subject: 'post')]
    public function discardAutosave(Request $request, Post $post): JsonResponse
    {
        $this->requireCsrfToken($request);
        $this->editor->discardAutosave($post);

        return $this->json(['ok' => true]);
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    #[IsGranted(PostVoter::DELETE, subject: 'post')]
    public function delete(Request $request, Post $post): Response
    {
        $this->requireCsrfToken($request);
        $locale = $post->getLocale();

        $this->contentManager->delete($post);
        $this->addFlash('success', 'flash.post_deleted');

        return $this->redirectToRoute('admin_post_index', $this->locales->isDefault($locale) ? [] : ['locale' => $locale]);
    }
}
