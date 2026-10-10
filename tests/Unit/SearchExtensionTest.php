<?php

namespace App\Tests\Unit;

use App\Twig\SearchExtension;
use PHPUnit\Framework\TestCase;

final class SearchExtensionTest extends TestCase
{
    public function testHighlightEscapesTextAndMarksMatches(): void
    {
        $extension = new SearchExtension();

        self::assertSame('Fresh <mark>Bread</mark> &amp; <mark>bröt</mark>chen', $extension->highlight('Fresh Bread & brötchen', ['bread', 'bröt']));
        self::assertSame('&lt;script&gt;', $extension->highlight('<script>', []));
        self::assertSame('a<mark>.</mark>b', $extension->highlight('a.b', ['.']), 'Search terms are not regular expressions.');
        self::assertSame('<mark>garden</mark>ing', $extension->highlight('gardening', ['gar', 'garden']));
    }

    public function testExcerptShowsThePassageAroundTheFirstMatch(): void
    {
        $extension = new SearchExtension();
        $text = str_repeat('Lorem ipsum dolor sit amet. ', 20).'The lighthouse stands here. '.str_repeat('Consetetur sadipscing elitr. ', 20);

        $excerpt = $extension->excerpt($text, ['lighthouse'], 120);

        self::assertStringContainsString('lighthouse', $excerpt);
        self::assertStringStartsWith('… ', $excerpt);
        self::assertStringEndsWith(' …', $excerpt);
        self::assertLessThanOrEqual(124, mb_strlen($excerpt));

        self::assertSame('Short text.', $extension->excerpt('Short text.', ['text']));
        self::assertStringStartsWith('Lorem ipsum', $extension->excerpt($text, ['nomatch'], 120), 'Without a match the text is shown from its beginning.');
        self::assertStringContainsString('Bäume', $extension->excerpt(str_repeat('Ä ö ü ß ', 60).'Bäume '.str_repeat('é à ', 80), ['bäume'], 60));
    }
}
