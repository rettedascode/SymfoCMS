<?php

namespace App\Twig;

use App\Service\SiteInfo;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Makes "site_name" and "site_tagline" available in every template.
 */
final class SiteGlobals extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly SiteInfo $site)
    {
    }

    public function getGlobals(): array
    {
        return ['site_name' => $this->site->name(), 'site_tagline' => $this->site->tagline()];
    }
}
