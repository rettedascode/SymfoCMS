<?php

namespace App\Tests\Unit;

use App\Service\VideoEmbed;
use App\Transfer\WordPressContentConverter;
use PHPUnit\Framework\TestCase;

final class WordPressContentConverterTest extends TestCase
{
    public function testBlockEditorContentBecomesBlocks(): void
    {
        $result = $this->convert(<<<'HTML'
            <!-- wp:paragraph -->
            <p>Hello <strong>world</strong>, read <a href="https://example.com/more">more</a>.</p>
            <!-- /wp:paragraph -->

            <!-- wp:paragraph -->
            <p>Second <span style="color:red">coloured</span> <code>code</code> paragraph.</p>
            <!-- /wp:paragraph -->

            <!-- wp:heading -->
            <h2 class="wp-block-heading">A <em>big</em> heading</h2>
            <!-- /wp:heading -->

            <!-- wp:heading {"level":4} -->
            <h4>Small heading</h4>
            <!-- /wp:heading -->

            <!-- wp:list -->
            <ul><li>One</li><li>Two <strong>bold</strong></li></ul>
            <!-- /wp:list -->

            <!-- wp:image {"id":12} -->
            <figure class="wp-block-image"><a href="https://example.com/full.jpg"><img src="https://example.com/wp-content/uploads/photo-300x200.jpg" alt="A photo"/></a><figcaption>The <em>caption</em></figcaption></figure>
            <!-- /wp:image -->

            <!-- wp:quote -->
            <blockquote class="wp-block-quote"><p>Stay hungry.</p><p>Stay foolish.</p><cite>— Steve</cite></blockquote>
            <!-- /wp:quote -->

            <!-- wp:separator -->
            <hr class="wp-block-separator"/>
            <!-- /wp:separator -->

            <!-- wp:code -->
            <pre class="wp-block-code"><code>if ($a &lt; $b) {
                echo "x";
            }</code></pre>
            <!-- /wp:code -->

            <!-- wp:embed {"url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ"} -->
            <figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">
            https://www.youtube.com/watch?v=dQw4w9WgXcQ
            </div></figure>
            <!-- /wp:embed -->

            <!-- wp:gallery -->
            <figure class="wp-block-gallery"><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure><figure class="wp-block-image"><img src="https://example.com/missing.jpg" alt=""/></figure></figure>
            <!-- /wp:gallery -->
            HTML);

        self::assertSame(['text', 'heading', 'heading', 'text', 'image', 'quote', 'divider', 'code', 'video', 'gallery'], array_column($result['blocks'], 'type'));
        self::assertSame('<p>Hello <strong>world</strong>, read <a href="https://example.com/more">more</a>.</p><p>Second coloured code paragraph.</p>', $result['blocks'][0]['data']['content']);
        self::assertSame(['text' => 'A big heading', 'level' => '2'], $result['blocks'][1]['data']);
        self::assertSame('3', $result['blocks'][2]['data']['level']);
        self::assertSame('<ul><li>One</li><li>Two <strong>bold</strong></li></ul>', $result['blocks'][3]['data']['content']);
        self::assertSame(['image' => 7, 'alt' => 'A photo', 'caption' => 'The caption'], $result['blocks'][4]['data']);
        self::assertSame(['text' => "Stay hungry.\n\nStay foolish.", 'author' => 'Steve'], $result['blocks'][5]['data']);
        self::assertStringStartsWith('if ($a < $b) {', $result['blocks'][7]['data']['code']);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $result['blocks'][8]['data']['url']);
        self::assertSame([7], $result['blocks'][9]['data']['images']);
        self::assertSame(['transfer.wordpress.note.gallery_images|1'], $result['notes']);
    }

    public function testClassicEditorContentBecomesBlocks(): void
    {
        $result = $this->convert(<<<'HTML'
            First paragraph with <b>bold</b> text
            and a line break.

            Second paragraph.
            <h3>Heading</h3>
            Text after the heading.

            [caption id="attachment_5" align="alignnone" width="300"]<a href="https://example.com/big.jpg"><img src="https://example.com/small.jpg" alt="Alt text" /></a> A caption[/caption]

            [gallery ids="5,6,99" columns="2"]

            https://youtu.be/dQw4w9WgXcQ

            <p>Text with an image <img src="https://example.com/inline.jpg" alt="Inline"> inside.</p>
            [contact-form-7 id="12" title="Contact"]
            [embed]https://vimeo.com/123456789[/embed]
            <!--more-->
            Last words.
            HTML);

        self::assertSame(['text', 'heading', 'text', 'image', 'gallery', 'video', 'text', 'image', 'video', 'text'], array_column($result['blocks'], 'type'));
        self::assertSame('<p>First paragraph with <b>bold</b> text<br> and a line break.</p><p>Second paragraph.</p>', (string) preg_replace('/\s+/', ' ', $result['blocks'][0]['data']['content']));
        self::assertSame('<p>Text after the heading.</p>', $result['blocks'][2]['data']['content']);
        self::assertSame(['image' => 7, 'alt' => 'Alt text', 'caption' => 'A caption'], $result['blocks'][3]['data']);
        self::assertSame([105, 106], $result['blocks'][4]['data']['images']);
        self::assertSame('<p>Text with an image inside.</p>', $result['blocks'][6]['data']['content']);
        self::assertSame('Inline', $result['blocks'][7]['data']['alt']);
        self::assertSame('https://vimeo.com/123456789', $result['blocks'][8]['data']['url']);
        self::assertSame('<p>Last words.</p>', $result['blocks'][9]['data']['content']);
        self::assertSame(['transfer.wordpress.note.shortcode|contact-form-7', 'transfer.wordpress.note.gallery_images|1'], $result['notes']);
    }

    public function testWhatCannotBeConvertedIsReportedAndTextIsKept(): void
    {
        $result = $this->convert(<<<'HTML'
            <!-- wp:latest-posts {"postsToShow":3} /-->
            <!-- wp:spacer /-->
            <div class="wp-block-columns"><div class="wp-block-column"><p>Left</p></div><div class="wp-block-column"><p>Right</p></div></div>
            <table><thead><tr><th>Name</th><th>Price</th></tr></thead><tbody><tr><td>Bread</td><td><strong>2 €</strong></td></tr></tbody></table>
            <script>alert(1)</script>
            <form action="/send"><input name="x"></form>
            <iframe src="https://maps.example.com/embed?x=1"></iframe>
            <figure class="wp-block-video"><video src="https://example.com/a.mp4"></video></figure>
            <img src="https://example.com/missing.jpg" alt="">
            <p></p>
            <p>&nbsp;</p>
            HTML);

        self::assertSame(['text', 'text'], array_column($result['blocks'], 'type'));
        self::assertSame('<p>Left</p><p>Right</p>', $result['blocks'][0]['data']['content']);
        self::assertSame('<p>Name | Price</p><p>Bread | <strong>2 €</strong></p>', $result['blocks'][1]['data']['content']);
        self::assertEqualsCanonicalizing([
            'transfer.wordpress.note.dynamic_block|latest-posts',
            'transfer.wordpress.note.table|',
            'transfer.wordpress.note.element|script',
            'transfer.wordpress.note.element|form',
            'transfer.wordpress.note.embed|https://maps.example.com/embed?x=1',
            'transfer.wordpress.note.element|video',
            'transfer.wordpress.note.image|https://example.com/missing.jpg',
        ], $result['notes']);

        self::assertSame(['blocks' => [], 'notes' => []], $this->convert("  \n "));
    }

    /**
     * @return array{blocks: list<array{type: string, data: array<string, mixed>}>, notes: list<string>}
     */
    private function convert(string $html): array
    {
        return (new WordPressContentConverter(new VideoEmbed()))->convert(
            $html,
            static fn (string $url, string $alt): ?int => str_contains($url, 'missing') ? null : 7,
            static fn (int $id): ?int => $id < 50 ? 100 + $id : null,
        );
    }
}
