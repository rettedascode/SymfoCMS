<?php

namespace App\Entity;

use App\Repository\RevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A snapshot of a page or post, taken every time it is saved.
 */
#[ORM\Entity(repositoryClass: RevisionRepository::class)]
#[ORM\Table(name: 'revisions')]
#[ORM\Index(name: 'idx_revisions_content', columns: ['content_type', 'content_id', 'created_at'])]
class Revision
{
    public const TYPE_PAGE = 'page';
    public const TYPE_POST = 'post';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     * @param array<string, string|null>                            $fields other restorable texts, e.g. the SEO title
     */
    public function __construct(
        #[ORM\Column(length: 10)]
        private string $contentType,
        #[ORM\Column]
        private int $contentId,
        #[ORM\Column(length: 200)]
        private string $title,
        #[ORM\Column(type: Types::JSON)]
        private array $blocks,
        #[ORM\Column(type: Types::JSON)]
        private array $fields = [],
        ?User $author = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->author = $author;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getContentId(): int
    {
        return $this->contentId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * @return array<string, string|null>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
