<?php

namespace App\Entity;

use App\Enum\ContentStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fields shared by everything that is edited with the block editor.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class Content
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    protected string $title = '';

    /** Unique within one language; see the unique constraints on Page and Post. */
    #[ORM\Column(length: 200)]
    #[Assert\Length(max: 200)]
    #[Assert\Regex(pattern: self::SLUG_PATTERN, message: 'content.slug_invalid')]
    protected string $slug = '';

    /** Language of this content, one of the codes configured in the settings. */
    #[ORM\Column(length: 10, options: ['default' => 'en'])]
    protected string $locale = 'en';

    /**
     * Shared by all language versions of the same page or post.
     * There is at most one version per language in a group.
     */
    #[ORM\Column(length: 32, options: ['default' => ''])]
    protected string $translationGroup;

    #[ORM\Column(length: 20, enumType: ContentStatus::class)]
    protected ContentStatus $status = ContentStatus::Draft;

    /**
     * Ordered list of blocks, each one shaped like {"type": "heading", "data": {...}}.
     *
     * @var list<array{type: string, data: array<string, mixed>}>
     */
    #[ORM\Column(type: Types::JSON)]
    protected array $blocks = [];

    /**
     * Unsaved editor state written by the autosave. Cleared on every real save.
     *
     * @var array{fields: array<string, string>, blocks: list<array{type: string, data: array<string, mixed>}>, savedAt: string}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $autosave = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    protected ?string $seoTitle = null;

    #[ORM\Column(length: 320, nullable: true)]
    #[Assert\Length(max: 320)]
    protected ?string $metaDescription = null;

    /** Image shown when the content is shared on social networks and in messengers. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Media $shareImage = null;

    #[ORM\Column]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column]
    protected \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->translationGroup = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = trim((string) $title);

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = trim((string) $slug);

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTranslationGroup(): string
    {
        return $this->translationGroup;
    }

    /**
     * Links this content to the language versions that share the given group.
     */
    public function setTranslationGroup(string $translationGroup): static
    {
        $this->translationGroup = $translationGroup;

        return $this;
    }

    public function getStatus(): ContentStatus
    {
        return $this->status;
    }

    public function setStatus(ContentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     */
    public function setBlocks(array $blocks): static
    {
        $this->blocks = $blocks;

        return $this;
    }

    /**
     * @return array{fields: array<string, string>, blocks: list<array{type: string, data: array<string, mixed>}>, savedAt: string}|null
     */
    public function getAutosave(): ?array
    {
        return $this->autosave;
    }

    /**
     * @param array{fields: array<string, string>, blocks: list<array{type: string, data: array<string, mixed>}>, savedAt: string}|null $autosave
     */
    public function setAutosave(?array $autosave): static
    {
        $this->autosave = $autosave;

        return $this;
    }

    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function setSeoTitle(?string $seoTitle): static
    {
        $this->seoTitle = '' === trim((string) $seoTitle) ? null : trim((string) $seoTitle);

        return $this;
    }

    public function getShareImage(): ?Media
    {
        return $this->shareImage;
    }

    public function setShareImage(?Media $shareImage): static
    {
        $this->shareImage = $shareImage;

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = '' === trim((string) $metaDescription) ? null : trim((string) $metaDescription);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Whether anonymous visitors may see this content.
     */
    public function isPublic(): bool
    {
        return ContentStatus::Published === $this->status;
    }
}
