<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One searchable thing (a page, a post or an image) as plain text.
 *
 * The rows are written by App\Service\SearchIndexer and never edited by hand.
 * The "folded" columns hold the same text in lower case: comparing them with
 * a lower-cased search term finds matches regardless of capitalisation on
 * every database, including umlauts and other non-English letters.
 */
#[ORM\Entity]
#[ORM\Table(name: 'search_index')]
#[ORM\UniqueConstraint(name: 'search_index_content', columns: ['kind', 'content_id'])]
#[ORM\Index(name: 'search_index_visible', columns: ['visible', 'locale'])]
class SearchEntry
{
    public const PAGE = 'page';
    public const POST = 'post';
    public const MEDIA = 'media';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $kind = '';

    #[ORM\Column]
    private int $contentId = 0;

    /** Language of the content; empty for images, which belong to all languages. */
    #[ORM\Column(length: 5)]
    private string $locale = '';

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column(length: 255)]
    private string $titleFolded = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $bodyFolded = '';

    /** True for published pages and posts: only these are found on the public website. */
    #[ORM\Column]
    private bool $visible = false;

    /** Publication date of a post; scheduled posts are found from this moment on. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $visibleFrom = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getContentId(): int
    {
        return $this->contentId;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function getVisibleFrom(): ?\DateTimeImmutable
    {
        return $this->visibleFrom;
    }
}
