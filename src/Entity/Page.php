<?php

namespace App\Entity;

use App\Repository\PageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'pages')]
#[ORM\UniqueConstraint(name: 'uniq_pages_locale_slug', columns: ['locale', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_pages_translation', columns: ['translation_group', 'locale'])]
#[UniqueEntity(['locale', 'slug'], message: 'content.slug_taken', errorPath: 'slug')]
class Page extends Content
{
    /**
     * First path segments used by the application itself. A page with one
     * of these slugs would never be reachable.
     */
    public const RESERVED_SLUGS = ['admin', 'blog', 'login', 'logout', 'assets', 'uploads', 'bundles', 'sitemap', 'feed'];

    #[ORM\Column]
    private bool $homepage = false;

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
