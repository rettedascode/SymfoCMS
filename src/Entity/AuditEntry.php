<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the activity log: who did what to which thing, and when.
 *
 * The rows are written by App\Audit\AuditLogger and never changed. Names
 * are stored as they were at that moment, so the log stays readable after a
 * user or a page has been deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'audit_log_created', columns: ['created_at'])]
#[ORM\Index(name: 'audit_log_user', columns: ['user_id'])]
#[ORM\Index(name: 'audit_log_subject', columns: ['subject_type', 'subject_id'])]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Id of the acting user; null for the system (console, imports) and for failed logins. */
    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(length: 120)]
    private string $userName = '';

    /** What happened, e.g. "created", "updated", "published", "deleted", "login". */
    #[ORM\Column(length: 40)]
    private string $action = '';

    /** The kind of thing it happened to, e.g. "page", "post", "media", "user", "settings". */
    #[ORM\Column(length: 30)]
    private string $subjectType = '';

    #[ORM\Column(nullable: true)]
    private ?int $subjectId = null;

    /** Title or name of the thing at that moment. */
    #[ORM\Column(length: 255)]
    private string $subjectLabel = '';

    /**
     * More about the change, e.g. the names of the changed fields. Never the old or new values.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    public function getSubjectLabel(): string
    {
        return $this->subjectLabel;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details ?? [];
    }
}
