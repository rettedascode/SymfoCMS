<?php

namespace App\Entity;

use App\Repository\MediaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Table(name: 'media')]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Unique, generated name of the original file on disk. */
    #[ORM\Column(length: 100, unique: true)]
    private string $filename = '';

    #[ORM\Column(length: 255)]
    private string $originalName = '';

    #[ORM\Column(length: 50)]
    private string $mimeType = '';

    #[ORM\Column]
    private int $size = 0;

    #[ORM\Column]
    private int $width = 0;

    #[ORM\Column]
    private int $height = 0;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $alt = '';

    /**
     * Resized versions keyed by variant name (thumb, medium, large).
     *
     * @var array<string, array{file: string, width: int, height: int}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $variants = [];

    /**
     * The most important spot of the image, from 0 (left/top) to 1 (right/bottom).
     * Wherever the image is cut to another shape, this spot stays visible.
     */
    #[ORM\Column(options: ['default' => 0.5])]
    private float $focalX = 0.5;

    #[ORM\Column(options: ['default' => 0.5])]
    private float $focalY = 0.5;

    /** File of the upload as it was before it was cropped or rotated, kept so the changes can be undone. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sourceFile = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = mb_substr($originalName, 0, 255);

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setDimensions(int $width, int $height): static
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function getAlt(): string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): static
    {
        $this->alt = trim((string) $alt);

        return $this;
    }

    /**
     * @return array<string, array{file: string, width: int, height: int}>
     */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /**
     * @param array<string, array{file: string, width: int, height: int}> $variants
     */
    public function setVariants(array $variants): static
    {
        $this->variants = $variants;

        return $this;
    }

    /**
     * File name of the requested variant, falling back to the original
     * when the image was too small for that variant to be generated.
     */
    public function getFile(string $variant = 'original'): string
    {
        return $this->variants[$variant]['file'] ?? $this->filename;
    }

    public function getFocalX(): float
    {
        return $this->focalX;
    }

    public function getFocalY(): float
    {
        return $this->focalY;
    }

    public function setFocalPoint(float $x, float $y): static
    {
        $this->focalX = round(max(0.0, min(1.0, $x)), 4);
        $this->focalY = round(max(0.0, min(1.0, $y)), 4);

        return $this;
    }

    /**
     * The focal point as a value for the CSS property "object-position", e.g. "30% 65%".
     */
    public function getFocusPosition(): string
    {
        return round($this->focalX * 100, 1).'% '.round($this->focalY * 100, 1).'%';
    }

    public function getSourceFile(): ?string
    {
        return $this->sourceFile;
    }

    public function setSourceFile(?string $sourceFile): static
    {
        $this->sourceFile = $sourceFile;

        return $this;
    }

    public function isAnimated(): bool
    {
        return 'image/gif' === $this->mimeType;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
