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
 * Planning views of the blog, arranged by dragging: the board (posts by status)
 * and the calendar (posts by publish date).
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
     * A month of the blog: every post on the day of its publish date. Dragging a post to another day changes the date.
     */
    #[Route('/calendar', name: 'calendar', methods: ['GET'])]
    public function calendar(Request $request): Response
    {
        $locale = $this->locales->fromRequest($request);
        $today = new \DateTimeImmutable('today');
        $month = \DateTimeImmutable::createFromFormat('!Y-m', $request->query->getString('month')) ?: $today->modify('first day of this month');

        // Whole weeks from Monday to Sunday, so the month has the shape of a calendar sheet.
        $first = $month->modify('monday this week');
        $last = $month->modify('last day of this month')->modify('sunday this week');
        $author = $this->isGranted(PostVoter::MANAGE_ALL) ? null : $this->currentUser();

        $byDay = [];
        foreach ($this->posts->findDated($locale, $first, $last->modify('+1 day'), $author) as $post) {
            $byDay[$post->getPublishedAt()?->format('Y-m-d')][] = ['post' => $post, 'movable' => $this->mayReschedule($post)];
        }

        $weeks = [];
        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $weeks[(int) floor($first->diff($day)->days / 7)][] = [
                'date' => $day,
                'in_month' => $day->format('Y-m') === $month->format('Y-m'),
                'today' => $day == $today,
                'posts' => $byDay[$day->format('Y-m-d')] ?? [],
            ];
        }

        return $this->render('admin/post/calendar.html.twig', [
            'weeks' => $weeks,
            'month' => $month,
            'previous' => $month->modify('-1 month')->format('Y-m'),
            'next' => $month->modify('+1 month')->format('Y-m'),
            'is_current' => $month->format('Y-m') === $today->format('Y-m'),
            'locale' => $locale,
        ]);
    }

    /**
     * A post was dropped on another day: {"id": 7, "date": "2026-10-14"}. The time of day stays.
     *
     * Moving a post that visitors can read into the future takes it off the website until then;
     * that needs "confirmed": true. Without it the answer is 409 with the question to ask.
     */
    #[Route('/calendar/move', name: 'calendar_move', methods: ['POST'])]
    public function moveInCalendar(Request $request): JsonResponse
    {
        $this->requireCsrfToken($request);
        $data = $this->body($request);
        $date = \is_string($data['date'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $data['date']) : false;
        if (!\is_int($data['id'] ?? null) || false === $date || $date->format('Y-m-d') !== $data['date']) {
            throw new BadRequestHttpException('The move needs the id of a post and a date such as 2026-10-14.');
        }

        $post = $this->posts->find($data['id']) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);
        if (!$this->mayReschedule($post)) {
            return $this->json(['error' => $this->translator->trans('calendar.error.not_allowed')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Only the day changes. A post that had no time yet goes out in the morning.
        $current = $post->getPublishedAt();
        $moved = null !== $current
            ? $date->setTime((int) $current->format('H'), (int) $current->format('i'), (int) $current->format('s'))
            : $date->setTime(9, 0);

        $wasPublic = $post->isPublic();
        if ($wasPublic && $moved > new \DateTimeImmutable() && true !== ($data['confirmed'] ?? false)) {
            return $this->json(['confirm' => $this->translator->trans('calendar.confirm_offline', [
                '%title%' => $post->getTitle(),
                '%date%' => $this->formatDay($moved),
            ])], Response::HTTP_CONFLICT);
        }

        $post->setPublishedAt($moved);
        $this->contentManager->save($post, $post->getBlocks());

        $message = match (true) {
            $wasPublic && !$post->isPublic() => 'calendar.moved_offline',
            !$wasPublic && $post->isPublic() => 'calendar.moved_public',
            default => 'calendar.moved',
        };

        return $this->json($this->describe($post) + [
            'time' => $moved->format('H:i'),
            'message' => $this->translator->trans($message, ['%date%' => $this->formatDay($moved)]),
        ]);
    }

    /**
     * Changing the date of something that is published decides when - and whether - visitors see it.
     * That is reserved for those who may publish; drafts are planned by everybody who may edit them.
     */
    private function mayReschedule(Post $post): bool
    {
        if (!$this->isGranted(PostVoter::EDIT, $post)) {
            return false;
        }

        return ContentStatus::Published !== $post->getStatus() || $this->review->can($post, ReviewWorkflow::UNPUBLISH);
    }

    private function formatDay(\DateTimeImmutable $date): string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return $date->format('Y-m-d');
        }

        return (string) (new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $date->getTimezone()))->format($date);
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
