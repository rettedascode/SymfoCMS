<?php

namespace App\Entity;

use App\Repository\SharedBlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Blocks that are maintained in one place and shown on many pages,
 * for example a call to action or opening hours.
 */
#[ORM\Entity(repositoryClass: SharedBlockRepository::class)]
#[ORM\Table(name: 'shared_blocks')]
#[ORM\HasLifecycleCallbacks]
class SharedBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /**
     * @var list<array{type: string, data: array<string, mixed>}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $blocks = [];

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
