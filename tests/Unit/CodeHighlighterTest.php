<?php

namespace App\Tests\Unit;

use App\Service\CodeHighlighter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeHighlighterTest extends TestCase
{
    public function testMarksKeywordsStringsNumbersAndComments(): void
    {
        $html = (new CodeHighlighter())->highlight("// greet\nconst name = \"World\";\nreturn 42;", 'javascript');

        self::assertStringContainsString('<span class="code-comment">// greet</span>', $html);
        self::assertStringContainsString('<span class="code-keyword">const</span>', $html);
        self::assertStringContainsString('<span class="code-string">&quot;World&quot;</span>', $html);
        self::assertStringContainsString('<span class="code-number">42</span>', $html);
    }

    public function testKeywordsInsideStringsAndCommentsAreLeftAlone(): void
    {
        $html = (new CodeHighlighter())->highlight('echo "return if"; # return', 'php');

        self::assertSame(1, substr_count($html, 'code-keyword'), 'Only "echo" is a keyword here.');
    }

    public function testHtmlTagsAndComments(): void
    {
        $html = (new CodeHighlighter())->highlight('<!-- note --><p class="x">Hi</p>', 'html');

        self::assertStringContainsString('<span class="code-comment">&lt;!-- note --&gt;</span>', $html);
        self::assertStringContainsString('<span class="code-tag">&lt;p class=&quot;x&quot;&gt;</span>Hi', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        foreach (array_keys(CodeHighlighter::LANGUAGES) as $language) {
            yield $language => [$language];
        }
        yield 'unknown' => ['klingon'];
    }

    /**
     * Whatever the language, the highlighter must never let markup from the code through
     * and must never lose or change a character.
     */
    #[DataProvider('languages')]
    public function testOutputIsEscapedAndComplete(string $language): void
    {
        $code = "<script>alert('x')</script>\n/* <b>c</b> */ # <i>\n\"<img src=x onerror=1>\" {{ a }} -- 'q' 12 & é\n";

        $html = (new CodeHighlighter())->highlight($code, $language);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertSame(0, preg_match('/<(?!\/?span\b)/', $html), 'Only <span> elements may appear in the output.');
        self::assertSame($code, html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }
}
