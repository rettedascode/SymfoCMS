<?php

namespace App\Service;

/**
 * Validates links typed by editors (button blocks, menu items).
 */
final class LinkSanitizer
{
    /**
     * Accepts links within the site ("/contact", "#top") and http(s), mailto
     * and tel URLs. Anything else, such as "javascript:", becomes an empty string.
     */
    public function sanitize(mixed $value): string
    {
        $url = \is_scalar($value) ? trim((string) $value) : '';
        if ('' === $url || mb_strlen($url) > 500 || preg_match('/[\s<>"\x00-\x1F]/', $url)) {
            return '';
        }

        if (preg_match('~^(/(?!/)|#|\?)~', $url) || preg_match('~^(https?://|mailto:|tel:)~i', $url)) {
            return $url;
        }

        // "example.com/page" was most likely meant as an external link.
        if (preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+(/.*)?$~i', $url)) {
            return 'https://'.$url;
        }

        return '';
    }
}
