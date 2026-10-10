<?php

namespace App\Dashboard;

use App\Entity\Content;
use App\Entity\FormSubmission;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Security\Voter\AdminAreaVoter;
use App\Security\Voter\PageVoter;
use App\Security\Voter\PostVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The start page of the admin area: a few widgets that every person arranges for themselves.
 *
 * Which widgets somebody gets depends on what they may see; in which order and how
 * wide they are is stored with the user.
 */
final class Dashboard
{
    public const RECENT = 'recent';
    public const DRAFTS = 'drafts';
    public const SCHEDULED = 'scheduled';
    public const SUBMISSIONS = 'submissions';

    /** The start page is a grid of this many columns ... */
    public const COLUMNS = 6;

    /** ... and a widget takes a third, a half, two thirds or all of them. */
    public const SIZES = [2, 3, 4, 6];

    /** Order and width for somebody who has not arranged anything yet. */
    private const DEFAULTS = [
        self::RECENT => 4,
        self::DRAFTS => 2,
        self::SCHEDULED => 3,
        self::SUBMISSIONS => 3,
    ];

    private const ENTRIES = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * The widgets the current user gets, in the order of the defaults.
     *
     * @return list<string>
     */
    public function available(): array
    {
        // The widget that not everybody gets is the last one, so the result stays a list.
        return array_filter(
            array_keys(self::DEFAULTS),
            fn (string $widget): bool => self::SUBMISSIONS !== $widget || $this->security->isGranted(AdminAreaVoter::MANAGE_FORMS),
        );
    }

    /**
     * The widgets of a user in their order, each with its width.
     *
     * What the user stored counts as far as it still makes sense: widgets they may no longer
     * see are left out, and widgets that are new to them are added at the end.
     *
     * @return list<array{id: string, size: int}>
     */
    public function layout(User $user): array
    {
        $available = $this->available();
        $layout = [];
        foreach ($user->getDashboardLayout() ?? [] as $entry) {
            // Stored data is not trusted to have the shape it should have.
            $id = \is_array($entry) ? ($entry['id'] ?? null) : null;
            if (\is_string($id) && \in_array($id, $available, true) && !isset($layout[$id])) {
                $size = $entry['size'] ?? null;
                $layout[$id] = ['id' => $id, 'size' => \in_array($size, self::SIZES, true) ? $size : self::DEFAULTS[$id]];
            }
        }
        foreach ($available as $id) {
            $layout[$id] ??= ['id' => $id, 'size' => self::DEFAULTS[$id]];
        }

        return array_values($layout);
    }

    /**
     * Stores the arrangement a user chose.
     *
     * @throws \InvalidArgumentException when it names a widget twice, one the user does not have, or a width that does not exist
     */
    public function arrange(User $user, mixed $widgets): void
    {
        $available = $this->available();
        if (!\is_array($widgets) || !array_is_list($widgets) || \count($widgets) !== \count($available)) {
            throw new \InvalidArgumentException('The layout has to name every widget once.');
        }

        $layout = [];
        foreach ($widgets as $entry) {
            $id = \is_array($entry) ? ($entry['id'] ?? null) : null;
            $size = \is_array($entry) ? ($entry['size'] ?? null) : null;
            if (!\is_string($id) || !\in_array($id, $available, true) || isset($layout[$id]) || !\in_array($size, self::SIZES, true)) {
                throw new \InvalidArgumentException('The layout names a widget or a width that does not exist.');
            }
            $layout[$id] = ['id' => $id, 'size' => $size];
        }

        $user->setDashboardLayout(array_values($layout));
        $this->entityManager->flush();
    }

    /**
     * What a widget shows to this user.
     *
     * @return array<string, mixed>
     */
    public function content(string $widget, User $user): array
    {
        return match ($widget) {
            self::RECENT => ['entries' => $this->recentChanges($user)],
            self::DRAFTS => ['entries' => $this->drafts($user)],
            self::SCHEDULED => ['entries' => $this->scheduled($user)],
            self::SUBMISSIONS => $this->submissions(),
            default => [],
        };
    }

    /**
     * Pages and posts the user works with, the latest change first.
     *
     * @return list<Content>
     */
    private function recentChanges(User $user): array
    {
        $changed = $this->posts($user, 'p.updatedAt', 'DESC');
        if ($this->security->isGranted(PageVoter::MANAGE)) {
            array_push($changed, ...$this->entityManager->getRepository(Page::class)->findBy([], ['updatedAt' => 'DESC'], self::ENTRIES));
        }
        usort($changed, static fn (Content $a, Content $b): int => $b->getUpdatedAt() <=> $a->getUpdatedAt());

        return \array_slice($changed, 0, self::ENTRIES);
    }

    /**
     * What the user wrote and has not published yet, including what waits for a review.
     *
     * @return list<Post>
     */
    private function drafts(User $user): array
    {
        /** @var list<Post> $posts */
        $posts = $this->entityManager->getRepository(Post::class)->createQueryBuilder('p')
            ->where('p.author = :author')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('author', $user)
            ->setParameter('statuses', [ContentStatus::Draft, ContentStatus::Review])
            ->orderBy('p.updatedAt', 'DESC')
            ->setMaxResults(self::ENTRIES)
            ->getQuery()
            ->getResult();

        return $posts;
    }

    /**
     * Posts that go out by themselves, the next one first.
     *
     * @return list<Post>
     */
    private function scheduled(User $user): array
    {
        return $this->posts($user, 'p.publishedAt', 'ASC', true);
    }

    /**
     * @return array{entries: list<FormSubmission>, unread: int}
     */
    private function submissions(): array
    {
        $repository = $this->entityManager->getRepository(FormSubmission::class);

        return [
            'entries' => $repository->findBy(['read' => false], ['createdAt' => 'DESC'], self::ENTRIES),
            'unread' => $repository->count(['read' => false]),
        ];
    }

    /**
     * Posts the user may see: authors their own, editors all.
     *
     * @return list<Post>
     */
    private function posts(User $user, string $orderBy, string $direction, bool $scheduledOnly = false): array
    {
        $qb = $this->entityManager->getRepository(Post::class)->createQueryBuilder('p')
            ->orderBy($orderBy, $direction)
            ->setMaxResults(self::ENTRIES);

        if (!$this->security->isGranted(PostVoter::MANAGE_ALL)) {
            $qb->andWhere('p.author = :author')->setParameter('author', $user);
        }
        if ($scheduledOnly) {
            $qb->andWhere('p.status = :published')
                ->andWhere('p.publishedAt > :now')
                ->setParameter('published', ContentStatus::Published)
                ->setParameter('now', new \DateTimeImmutable());
        }

        /** @var list<Post> $posts */
        $posts = $qb->getQuery()->getResult();

        return $posts;
    }
}
