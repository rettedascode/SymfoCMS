<?php

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * Names for the entries of the activity log.
 */
final class AuditExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * The name of a changed field in the language of the reader. Fields without a translation,
     * e.g. those of an extension, are shown as readable words: "seoTitle" becomes "seo title".
     */
    #[AsTwigFilter('audit_field')]
    public function field(string $field): string
    {
        $key = 'audit.field.'.$field;
        $translated = $this->translator->trans($key);

        return $translated !== $key ? $translated : strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $field));
    }
}
