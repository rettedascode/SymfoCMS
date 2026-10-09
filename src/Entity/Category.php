<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'categories')]
#[ORM\UniqueConstraint(name: 'uniq_categories_locale_slug', columns: ['locale', 'slug'])]
#[UniqueEntity(['locale', 'slug'], message: 'content.slug_taken', errorPath: 'slug')]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: Content::SLUG_PATTERN, message: 'content.slug_invalid')]
    private string $slug = '';

    /** Language of the posts this category groups. */
    #[ORM\Column(length: 10, options: ['default' => 'en'])]
    private string $locale = 'en';

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = trim((string) $slug);

        return $this;
    }
}
