<?php

namespace App\Controller\Admin;

use App\Entity\Post;
use App\Enum\ContentStatus;
use App\Form\NewContentType;
use App\Form\PostType;
use App\Repository\PostRepository;
use App\Security\Voter\PostVoter;
use App\Service\ContentEditor;
use App\Service\ContentManager;
use App\Service\SlugGenerator;
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
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted(PostVoter::CREATE)]
    public function index(): Response
    {
        // Authors only see what they wrote themselves.
        $author = $this->isGranted(PostVoter::MANAGE_ALL) ? null : $this->currentUser();

        return $this->render('admin/post/index.html.twig', [
            'posts' => $this->posts->findForAdmin($author),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted(PostVoter::CREATE)]
    public function new(Request $request, SlugGenerator $slugGenerator): Response
    {
        $form = $this->createForm(NewContentType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $title = (string) $form->get('title')->getData();
            $post = (new Post())
                ->setTitle($title)
                ->setSlug($slugGenerator->unique($title, Post::class))
                ->setAuthor($this->currentUser());

            $this->contentManager->save($post, []);

            return $this->redirectToRoute('admin_post_edit', ['id' => $post->getId()]);
        }

        return $this->render('admin/content/new.html.twig', [
            'form' => $form,
            'heading' => 'post.new',
            'intro' => 'post.new_intro',
            'back' => $this->generateUrl('admin_post_index'),
        ]);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET', 'POST'])]
    #[IsGranted(PostVoter::EDIT, subject: 'post')]
    public function edit(Request $request, Post $post): Response
    {
        $form = $this->createForm(PostType::class, $post, [
            'can_change_author' => $this->isGranted(PostVoter::MANAGE_ALL),
        ]);
        $form->handleRequest($request);
        $blocks = $post->getBlocks();
        $errors = [];

        if ($form->isSubmitted()) {
            [$blocks, $errors] = $this->editor->submittedBlocks($form);

            if ($form->isValid()) {
                if ($request->request->has('publish')) {
                    $post->setStatus(ContentStatus::Published);
                }
                // Publishing without a date means "now"; a date in the future schedules the post.
                if (ContentStatus::Published === $post->getStatus() && null === $post->getPublishedAt()) {
                    $post->setPublishedAt(new \DateTimeImmutable());
                }

                $this->contentManager->save($post, $blocks);
                $this->addFlash('success', match (true) {
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
            'editor_config' => $this->editor->config($post, $blocks, [
                'autosave' => $this->generateUrl('admin_post_autosave', ['id' => $post->getId()]),
                'discard' => $this->generateUrl('admin_post_discard_autosave', ['id' => $post->getId()]),
            ], 'post', $errors),
        ]);
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

        $this->contentManager->delete($post);
        $this->addFlash('success', 'flash.post_deleted');

        return $this->redirectToRoute('admin_post_index');
    }
}
