<?php

namespace App\Entity;

use App\Enum\ContentStatus;
use App\Repository\PostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'posts')]
#[ORM\Index(name: 'idx_posts_listing', columns: ['status', 'published_at'])]
#[ORM\UniqueConstraint(name: 'uniq_posts_locale_slug', columns: ['locale', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_posts_translation', columns: ['translation_group', 'locale'])]
#[UniqueEntity(['locale', 'slug'], message: 'content.slug_taken', errorPath: 'slug')]
class Post extends Content
{
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 600)]
    private ?string $excerpt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Media $coverImage = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $author = null;

    /**
     * A published post with a date in the future is scheduled: it becomes
     * visible on its own once that date has passed.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): static
    {
        $this->excerpt = '' === trim((string) $excerpt) ? null : trim((string) $excerpt);

        return $this;
    }

    public function getCoverImage(): ?Media
    {
        return $this->coverImage;
    }

    public function setCoverImage(?Media $coverImage): static
    {
        $this->coverImage = $coverImage;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function isScheduled(): bool
    {
        return ContentStatus::Published === $this->status
            && null !== $this->publishedAt
            && $this->publishedAt > new \DateTimeImmutable();
    }

    public function isPublic(): bool
    {
        return parent::isPublic() && null !== $this->publishedAt && !$this->isScheduled();
    }
}
