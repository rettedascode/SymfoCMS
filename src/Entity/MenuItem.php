<?php

namespace App\Entity;

use App\Enum\MenuItemType;
use App\Enum\MenuLocation;
use App\Repository\MenuItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MenuItemRepository::class)]
#[ORM\Table(name: 'menu_items')]
#[ORM\Index(name: 'idx_menu_items_location', columns: ['location', 'position'])]
class MenuItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: MenuLocation::class)]
    private MenuLocation $location = MenuLocation::Main;

    #[ORM\Column(length: 20, enumType: MenuItemType::class)]
    private MenuItemType $type = MenuItemType::Url;

    #[ORM\Column(length: 120)]
    private string $label = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Page $page = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Post $post = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    #[ORM\ManyToOne(inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?MenuItem $parent = null;

    /** @var Collection<int, MenuItem> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $children;

    #[ORM\Column]
    private int $position = 0;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocation(): MenuLocation
    {
        return $this->location;
    }

    public function setLocation(MenuLocation $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getType(): MenuItemType
    {
        return $this->type;
    }

    public function setType(MenuItemType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = mb_substr(trim($label), 0, 120);

        return $this;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function setPage(?Page $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function getPost(): ?Post
    {
        return $this->post;
    }

    public function setPost(?Post $post): static
    {
        $this->post = $post;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getParent(): ?MenuItem
    {
        return $this->parent;
    }

    public function setParent(?MenuItem $parent): static
    {
        $this->parent = $parent;
        if (null !== $parent && !$parent->children->contains($this)) {
            $parent->children->add($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, MenuItem>
     */
    public function getChildren(): Collection
    {
        return $this->children;
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
}
