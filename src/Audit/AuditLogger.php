<?php

namespace App\Audit;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes the activity log.
 *
 * Changes of content, users and settings are recorded automatically by
 * App\EventSubscriber\AuditSubscriber. Call log() for things that are not a
 * change of one database row, such as an import.
 */
final class AuditLogger
{
    public const TABLE = 'audit_log';

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        #[Autowire(env: 'int:APP_AUDIT_DAYS')]
        private readonly int $retentionDays,
    ) {
    }

    /**
     * @param string               $action  what happened, e.g. "created" (translated as "audit.action.<action>")
     * @param string               $type    the kind of thing, e.g. "page" (translated as "audit.type.<type>")
     * @param array<string, mixed> $details e.g. ['fields' => ['title', 'blocks']]; never store secrets or personal data here
     * @param User|null            $actor   defaults to the logged-in user; without one the entry belongs to the system
     */
    public function log(string $action, string $type, ?int $id, string $label, array $details = [], ?User $actor = null): void
    {
        $actor ??= $this->security->getUser() instanceof User ? $this->security->getUser() : null;

        try {
            $this->connection->insert(self::TABLE, [
                'created_at' => new \DateTimeImmutable(),
                'user_id' => $actor?->getId(),
                'user_name' => mb_substr($actor?->getName() ?? '', 0, 120),
                'action' => mb_substr($action, 0, 40),
                'subject_type' => mb_substr($type, 0, 30),
                'subject_id' => $id,
                'subject_label' => mb_substr($label, 0, 255),
                'details' => [] !== $details ? $details : null,
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'details' => Types::JSON,
            ]);
        } catch (\Throwable) {
            // The log must never be the reason why saving something fails (e.g. before the table exists).
        }
    }

    /**
     * Removes entries that are older than the retention period (APP_AUDIT_DAYS, one year by default).
     */
    public function purge(): int
    {
        if ($this->retentionDays < 1) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM '.self::TABLE.' WHERE created_at < :limit',
            ['limit' => new \DateTimeImmutable('-'.$this->retentionDays.' days')],
            ['limit' => Types::DATETIME_IMMUTABLE],
        );
    }
}
