<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

/**
 * Marks the words somebody searched for in a piece of text.
 */
final class SearchExtension
{
    /**
     * Escapes the text and wraps every match in <mark>.
     *
     * @param list<string> $terms
     */
    #[AsTwigFilter('highlight', isSafe: ['html'])]
    public function highlight(string $text, array $terms): string
    {
        $pattern = $this->pattern($terms);
        $parts = null !== $pattern ? preg_split($pattern, $text, -1, \PREG_SPLIT_DELIM_CAPTURE) : false;
        if (false === $parts) {
            return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE);
        }

        $html = '';
        foreach ($parts as $i => $part) {
            $escaped = htmlspecialchars($part, \ENT_QUOTES | \ENT_SUBSTITUTE);
            // With the delimiters captured, every second part is a match.
            $html .= 1 === $i % 2 ? '<mark>'.$escaped.'</mark>' : $escaped;
        }

        return $html;
    }

    /**
     * A short passage of the text around the first match.
     *
     * @param list<string> $terms
     */
    #[AsTwigFilter('search_excerpt')]
    public function excerpt(string $text, array $terms, int $length = 220): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $start = 0;
        $pattern = $this->pattern($terms);
        if (null !== $pattern && 1 === preg_match($pattern, $text, $match, \PREG_OFFSET_CAPTURE)) {
            // The offset counts bytes; the passage is cut by characters.
            $position = mb_strlen(substr($text, 0, $match[0][1]));
            $start = max(0, $position - intdiv($length, 3));
        }

        $start = min($start, mb_strlen($text) - $length);
        if ($start > 0) {
            // Begin at a word instead of in the middle of one.
            $space = mb_strpos($text, ' ', $start);
            $start = false !== $space && $space - $start < 30 ? $space + 1 : $start;
        }

        $passage = mb_substr($text, $start, $length);
        if ($start + $length < mb_strlen($text)) {
            $space = mb_strrpos($passage, ' ');
            $passage = (false !== $space && $space > $length - 30 ? mb_substr($passage, 0, $space) : $passage).' …';
        }

        return ($start > 0 ? '… ' : '').$passage;
    }

    /**
     * @param list<string> $terms
     */
    private function pattern(array $terms): ?string
    {
        $terms = array_filter($terms, static fn (string $term): bool => '' !== $term);
        if ([] === $terms) {
            return null;
        }

        // Longer words first, so "garden" wins over "gar" where both would match.
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return '/('.implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)).')/iu';
    }
}
