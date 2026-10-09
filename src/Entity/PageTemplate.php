<?php

namespace App\Entity;

use App\Repository\PageTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A starting point for new pages that an admin saved from an existing page.
 * The built-in templates live in App\Service\PageTemplates.
 */
#[ORM\Entity(repositoryClass: PageTemplateRepository::class)]
#[ORM\Table(name: 'page_templates')]
class PageTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     */
    public function __construct(
        #[ORM\Column(length: 120)]
        private string $name,
        #[ORM\Column(type: Types::JSON)]
        private array $blocks,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
