<?php

namespace App\Entity;

use App\Repository\RedirectRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sends visitors of an address that no longer exists to its new place (HTTP 301).
 */
#[ORM\Entity(repositoryClass: RedirectRepository::class)]
#[ORM\Table(name: 'redirects')]
class Redirect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Path on this website, e.g. "/old-page". Always starts with a slash and has no trailing slash. */
    #[ORM\Column(length: 255, unique: true)]
    private string $source = '';

    /** Path on this website or a full web address. */
    #[ORM\Column(length: 500)]
    private string $target = '';

    /** True when the redirect was created because the address of a page or post changed. */
    #[ORM\Column]
    private bool $automatic = false;

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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = (string) $source;

        return $this;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function setTarget(?string $target): static
    {
        $this->target = (string) $target;

        return $this;
    }

    public function isAutomatic(): bool
    {
        return $this->automatic;
    }

    public function setAutomatic(bool $automatic): static
    {
        $this->automatic = $automatic;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
