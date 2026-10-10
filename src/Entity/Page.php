<?php

namespace App\Entity;

use App\DragDrop\TreeNode;
use App\Repository\PageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'pages')]
#[ORM\UniqueConstraint(name: 'uniq_pages_locale_slug', columns: ['locale', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_pages_translation', columns: ['translation_group', 'locale'])]
#[UniqueEntity(['locale', 'slug'], message: 'content.slug_taken', errorPath: 'slug')]
class Page extends Content implements TreeNode
{
    /**
     * First path segments used by the application itself. A page with one
     * of these slugs would never be reachable.
     */
    public const RESERVED_SLUGS = ['admin', 'blog', 'login', 'logout', 'assets', 'uploads', 'bundles', 'sitemap', 'feed', 'search', 'api', 'install'];

    #[ORM\Column]
    private bool $homepage = false;

    /** The page this one belongs to. The address of a child page starts with the address of its parent. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Page $parent = null;

    /** Place among the pages with the same parent, 0 being the first. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function getParent(): ?Page
    {
        return $this->parent;
    }

    public function setParent(?TreeNode $parent): static
    {
        \assert(null === $parent || $parent instanceof self);
        $this->parent = $parent;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /**
     * The part of the address after the domain (and the language): the slugs of all parents and its own, e.g. "about/team".
     */
    public function getPath(): string
    {
        $segments = [$this->slug];
        // The limit keeps a loop in broken data from hanging the request.
        for ($parent = $this->parent, $i = 0; null !== $parent && $i < 20; $parent = $parent->getParent(), ++$i) {
            array_unshift($segments, $parent->getSlug());
        }

        return implode('/', $segments);
    }

    /**
     * Level in the tree: 1 for a page without a parent.
     */
    public function getDepth(): int
    {
        $depth = 1;
        for ($parent = $this->parent; null !== $parent && $depth < 20; $parent = $parent->getParent()) {
            ++$depth;
        }

        return $depth;
    }

    public function isHomepage(): bool
    {
        return $this->homepage;
    }

    public function setHomepage(bool $homepage): static
    {
        $this->homepage = $homepage;

        return $this;
    }
}
