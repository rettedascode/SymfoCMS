<?php

namespace App\Controller\Admin;

use App\Entity\Post;
use App\Enum\ContentStatus;
use App\Repository\PostRepository;
use App\Security\Voter\PostVoter;
use App\Service\ContentLocales;
use App\Service\ContentManager;
use App\Workflow\ReviewWorkflow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Planning views of the blog, arranged by dragging: the board (posts by status).
 *
 * Everybody sees the posts they may edit - authors their own, editors all - and
 * every change goes through the same workflow and voters as the post editor.
 */
#[Route('/admin/posts', name: 'admin_editorial_')]
#[IsGranted(PostVoter::CREATE)]
final class EditorialController extends AdminController
{
    /** Published posts are endless; the board shows the newest ones. */
    public const BOARD_PUBLISHED_LIMIT = 20;

    public function __construct(
        private readonly PostRepository $posts,
        private readonly ContentLocales $locales,
        private readonly ReviewWorkflow $review,
        private readonly ContentManager $contentManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Posts as cards in one column per status. Dragging a card into another column changes its status.
     */
    #[Route('/board', name: 'board', methods: ['GET'])]
    public function board(Request $request): Response
    {
        $locale = $this->locales->fromRequest($request);
        $columns = [];
        foreach (ContentStatus::cases() as $status) {
            $columns[$status->value] = [];
        }

        $hidden = 0;
        foreach ($this->visiblePosts($locale) as $post) {
            $status = $post->getStatus()->value;
            // Scheduled posts are plans and always shown; of the posts that are out, only the newest.
            if (ContentStatus::Published === $post->getStatus() && !$post->isScheduled() && \count(array_filter($columns[$status], static fn (array $card): bool => !$card['post']->isScheduled())) >= self::BOARD_PUBLISHED_LIMIT) {
                ++$hidden;

                continue;
            }

            $columns[$status][] = ['post' => $post, 'allowed' => $this->allowedStatuses($post)];
        }

        // What is out or planned is sorted by its date, newest first; everything else by the last change.
        usort($columns[ContentStatus::Published->value], static fn (array $a, array $b): int => $b['post']->getPublishedAt() <=> $a['post']->getPublishedAt());

        return $this->render('admin/post/board.html.twig', [
            'columns' => $columns,
            'hidden' => $hidden,
            'locale' => $locale,
        ]);
    }

    /**
     * A card was dropped into another column: {"id": 7, "status": "published"}.
     *
     * Taking a post that visitors can read off the website needs "confirmed": true;
     * without it the answer is 409 with the question to ask.
     */
    #[Route('/board/move', name: 'board_move', methods: ['POST'])]
    public function moveOnBoard(Request $request): JsonResponse
    {
        $this->requireCsrfToken($request);
        $data = $this->body($request);
        $status = \is_string($data['status'] ?? null) ? ContentStatus::tryFrom($data['status']) : null;
        if (!\is_int($data['id'] ?? null) || null === $status) {
            throw new BadRequestHttpException('The move needs the id of a post and a status.');
        }

        $post = $this->posts->find($data['id']) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);

        if ($status !== $post->getStatus()) {
            if (!\in_array($status, $this->review->reachableStatuses($post), true)) {
                return $this->json(['error' => $this->translator->trans('board.error.not_allowed', ['%status%' => $this->translator->trans($status->label())])], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($post->isPublic() && true !== ($data['confirmed'] ?? false)) {
                return $this->json(['confirm' => $this->translator->trans('board.confirm_offline', ['%title%' => $post->getTitle()])], Response::HTTP_CONFLICT);
            }

            $this->review->moveTo($post, $status);
            // Publishing without a date means "now", exactly as in the post editor.
            if (ContentStatus::Published === $post->getStatus() && null === $post->getPublishedAt()) {
                $post->setPublishedAt(new \DateTimeImmutable());
            }
            $this->contentManager->save($post, $post->getBlocks());
        }

        return $this->json($this->describe($post));
    }

    /**
     * The posts the current user works with: authors only see what they wrote themselves.
     *
     * @return list<Post>
     */
    private function visiblePosts(string $locale): array
    {
        return $this->posts->findForAdmin($locale, $this->isGranted(PostVoter::MANAGE_ALL) ? null : $this->currentUser());
    }

    /**
     * The statuses the current user may give the post, as the columns of the board know them.
     *
     * @return list<string>
     */
    private function allowedStatuses(Post $post): array
    {
        return array_map(static fn (ContentStatus $status): string => $status->value, $this->review->reachableStatuses($post));
    }

    /**
     * What the screen shows about a post after a move.
     *
     * @return array<string, mixed>
     */
    private function describe(Post $post): array
    {
        $state = $post->isScheduled() ? 'scheduled' : $post->getStatus()->value;

        return [
            'id' => $post->getId(),
            'status' => $post->getStatus()->value,
            'state' => $state,
            'label' => $this->translator->trans('status.'.$state),
            'allowed' => $this->allowedStatuses($post),
            'date' => $post->getPublishedAt()?->format('Y-m-d'),
        ];
    }

    /**
     * @return array<mixed, mixed>
     */
    private function body(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('The request is not valid JSON.');
        }

        return \is_array($data) ? $data : throw new BadRequestHttpException('The request is not an object.');
    }
}
