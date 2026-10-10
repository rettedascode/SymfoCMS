<?php

namespace App\EventSubscriber;

use App\Audit\AuditLogger;
use App\Entity\Category;
use App\Entity\Content;
use App\Entity\Form;
use App\Entity\Media;
use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\PageTemplate;
use App\Entity\Post;
use App\Entity\Redirect;
use App\Entity\Setting;
use App\Entity\SharedBlock;
use App\Entity\User;
use App\Enum\ContentStatus;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Fills the activity log: every time Doctrine stores a change of content,
 * media, users or settings, one entry says who did it. Logins are recorded
 * as well.
 *
 * Only the names of changed fields are kept, never their values - the log
 * shows that a password was changed, not what it is.
 */
#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
#[AsDoctrineListener(Events::onClear)]
final class AuditSubscriber implements ResetInterface
{
    /** Fields that change on their own or carry no information for a reader of the log. */
    private const IGNORED_FIELDS = ['updatedAt', 'createdAt', 'autosave', 'totpLastStep', 'reviewNoteBy'];

    /** @var list<array{string, object|null, string, int|null, string, array<string, mixed>}> action, entity (for new ids), type, id, label, details */
    private array $pending = [];

    public function __construct(private readonly AuditLogger $logger)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        $menus = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof MenuItem) {
                $menus[$entity->getLocation()->value.' ('.$entity->getLocale().')'] = true;
            } elseif (null !== $subject = $this->describe($entity)) {
                $this->pending[] = ['created', $entity, $subject[0], null, $subject[1], []];
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof MenuItem) {
                $menus[$entity->getLocation()->value.' ('.$entity->getLocale().')'] = true;
                continue;
            }

            $subject = $this->describe($entity);
            $changes = array_diff_key($unitOfWork->getEntityChangeSet($entity), array_flip(self::IGNORED_FIELDS));
            // Some databases return the keys of JSON data in another order, which is not a change.
            $changes = array_filter($changes, fn (mixed $change): bool => !\is_array($change) || !\is_array($change[0] ?? null) || !\is_array($change[1] ?? null) || $this->canonical($change[0]) !== $this->canonical($change[1]));
            if (null === $subject || [] === $changes) {
                continue;
            }

            $this->pending[] = [$this->action($entity, $changes), null, $subject[0], $this->id($entity), $subject[1], ['fields' => array_keys($changes)]];
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof MenuItem) {
                $menus[$entity->getLocation()->value.' ('.$entity->getLocale().')'] = true;
            } elseif (null !== $subject = $this->describe($entity)) {
                $this->pending[] = ['deleted', null, $subject[0], $this->id($entity), $subject[1], []];
            }
        }

        // Saving a menu rewrites all of its entries: that is one change for the reader, not twenty.
        foreach (array_keys($menus) as $menu) {
            $this->pending[] = ['updated', null, 'menu', null, (string) $menu, []];
        }
    }

    public function postFlush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as [$action, $entity, $type, $id, $label, $details]) {
            $this->logger->log($action, $type, $id ?? (null !== $entity ? $this->id($entity) : null), $label, $details);
        }
    }

    public function onClear(): void
    {
        $this->pending = [];
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    #[AsEventListener]
    public function onLogin(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $this->logger->log('login', 'user', $user->getId(), $user->getName(), [], $user);
        }
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        // The address that was tried helps to tell a typo from an attack. Passwords are never recorded.
        $email = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier()
            ?? $event->getRequest()->request->getString('_username');

        $this->logger->log('login_failed', 'user', null, mb_substr((string) $email, 0, 180));
    }

    /**
     * @return array{string, string}|null type and label, or null for things that are not logged
     */
    private function describe(object $entity): ?array
    {
        return match (true) {
            $entity instanceof Page => ['page', $entity->getTitle()],
            $entity instanceof Post => ['post', $entity->getTitle()],
            $entity instanceof Category => ['category', $entity->getName()],
            $entity instanceof Media => ['media', $entity->getOriginalName()],
            $entity instanceof User => ['user', $entity->getName()],
            $entity instanceof Form => ['form', $entity->getName()],
            $entity instanceof SharedBlock => ['shared_block', $entity->getName()],
            $entity instanceof PageTemplate => ['page_template', $entity->getName()],
            $entity instanceof Redirect => ['redirect', $entity->getSource()],
            $entity instanceof Setting => ['settings', $entity->getName()],
            default => null,
        };
    }

    /**
     * A change of status gets its own word, because that is what people look for in the log.
     *
     * @param array<string, mixed> $changes
     */
    private function action(object $entity, array $changes): string
    {
        if (!$entity instanceof Content || !\is_array($changes['status'] ?? null)) {
            return 'updated';
        }

        // Depending on where the entity came from, Doctrine reports the enum or its value.
        [$from, $to] = array_map(
            static fn (mixed $status): ?ContentStatus => $status instanceof ContentStatus ? $status : (\is_string($status) ? ContentStatus::tryFrom($status) : null),
            [$changes['status'][0] ?? null, $changes['status'][1] ?? null],
        );

        return match (true) {
            ContentStatus::Published === $to => 'published',
            ContentStatus::Review === $to => 'submitted',
            ContentStatus::Published === $from => 'unpublished',
            ContentStatus::Review === $from => 'returned',
            default => 'updated',
        };
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed> the same data with all keys in a fixed order
     */
    private function canonical(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = $this->canonical($value);
            }
        }
        if (!array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }

    private function id(object $entity): ?int
    {
        return method_exists($entity, 'getId') && \is_int($entity->getId()) ? $entity->getId() : null;
    }
}
