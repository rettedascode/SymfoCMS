<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

/**
 * Gallery, video, questions and answers, map and code blocks: saving and public rendering.
 */
final class BlockTypesTest extends AppWebTestCase
{
    public function testNewBlockTypesAreSavedAndRendered(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Showcase', ContentStatus::Published);
        $first = $this->createMedia('first.png', 'First picture');
        $second = $this->createMedia('second.png', 'Second picture');

        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submitForm('Save changes', ['page[blocks]' => $this->json([
            ['type' => 'gallery', 'data' => ['images' => [$second->getId(), 99999, 'x', $first->getId()], 'columns' => '4']],
            ['type' => 'video', 'data' => ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Our film', 'poster' => $first->getId()]],
            ['type' => 'accordion', 'data' => ['items' => [
                ['question' => 'Is it free?', 'answer' => '<p>Yes, <strong>completely</strong>.<script>x</script></p>', 'ignored' => 'dropped'],
                ['question' => '', 'answer' => '<p>No question, not shown.</p>'],
                'not an item',
            ]]],
            ['type' => 'map', 'data' => ['location' => ['lat' => '52.5200071', 'lon' => 13.404954, 'junk' => 1], 'label' => 'Alexanderplatz, Berlin', 'zoom' => 'town']],
            ['type' => 'code', 'data' => ['code' => "<?php\n    echo \"<b>Hi</b>\"; // greet\n", 'language' => 'php']],
        ])]);
        self::assertResponseRedirects();

        $blocks = $this->entity(Page::class, $page->getId())->getBlocks();
        self::assertSame([$second->getId(), 99999, $first->getId()], $blocks[0]['data']['images'], 'Only ids are kept, in the chosen order.');
        self::assertCount(2, $blocks[2]['data']['items']);
        self::assertEquals(['question' => 'Is it free?', 'answer' => '<p>Yes, <strong>completely</strong>.</p>'], $blocks[2]['data']['items'][0]);
        self::assertEquals(['lat' => 52.520007, 'lon' => 13.404954], $blocks[3]['data']['location']);
        self::assertSame("<?php\n    echo \"<b>Hi</b>\"; // greet", $blocks[4]['data']['code'], 'Indentation and line breaks of code are kept.');

        $crawler = $this->client->request('GET', '/showcase');
        self::assertResponseIsSuccessful();

        // Gallery: existing images in order, each linking to its large version.
        self::assertSame(['Second picture', 'First picture'], $crawler->filter('.block-gallery--4 img')->each(static fn ($img) => $img->attr('alt')));
        self::assertCount(2, $crawler->filter('.block-gallery a[href*="/uploads/media/"]'));

        // Video: a button that knows the privacy-friendly player address, and a local poster image.
        $button = $crawler->filter('.block-video [data-embed-src]');
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0', $button->attr('data-embed-src'));
        self::assertStringContainsString('Our film', $button->text());
        self::assertStringContainsString('only loaded from YouTube when you click', $crawler->filter('.block-video .embed__notice')->text());
        self::assertStringStartsWith('/uploads/media/', (string) $crawler->filter('.block-video .embed__poster')->attr('src'));

        // Questions and answers: native details elements, incomplete entries left out.
        self::assertCount(1, $crawler->filter('.block-accordion details'));
        self::assertSelectorTextSame('.block-accordion summary', 'Is it free?');
        self::assertSelectorTextContains('.block-accordion__answer strong', 'completely');

        // Map: OpenStreetMap, marker at the place.
        $map = $crawler->filter('.block-map [data-embed-src]');
        self::assertStringStartsWith('https://www.openstreetmap.org/export/embed.html?bbox=', (string) $map->attr('data-embed-src'));
        self::assertStringContainsString('marker=52.520007%2C13.404954', (string) $map->attr('data-embed-src'));
        self::assertSelectorTextSame('.block-map .embed__label', 'Alexanderplatz, Berlin');

        // Code: escaped and highlighted.
        self::assertSelectorTextContains('.block-code code.language-php', 'echo "<b>Hi</b>";');
        self::assertSelectorTextSame('.block-code .code-keyword', 'echo');
        self::assertSelectorTextSame('.block-code .code-comment', '// greet');
        self::assertCount(0, $crawler->filter('.block-code b'), 'Markup inside code is shown, not interpreted.');
    }

    /**
     * Privacy: until the visitor clicks, the page must not contain anything
     * that makes the browser contact a video platform or map server.
     */
    public function testNothingIsLoadedFromThirdPartiesBeforeTheClick(): void
    {
        $page = $this->createPage('Embeds', ContentStatus::Published);
        $page->setBlocks([
            ['type' => 'video', 'data' => ['url' => 'https://vimeo.com/123456789', 'title' => '', 'poster' => null]],
            ['type' => 'map', 'data' => ['location' => ['lat' => 48.1, 'lon' => 11.5], 'label' => '', 'zoom' => 'street']],
        ]);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/embeds');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-embed-src]'));
        self::assertCount(0, $crawler->filter('iframe, embed, object, video, audio'));

        foreach ($crawler->filter('[src], [srcset], link[href], [poster], [style*="url("]') as $element) {
            self::assertInstanceOf(\DOMElement::class, $element);
            foreach (['src', 'srcset', 'href', 'poster'] as $attribute) {
                $value = $element->getAttribute($attribute);
                self::assertDoesNotMatchRegularExpression('~^(https?:)?//(?!localhost)~', $value, \sprintf('<%s %s="%s"> would be loaded from another website.', $element->nodeName, $attribute, $value));
            }
        }
    }

    public function testInvalidEmbedsAreRejectedWithAHelpfulMessage(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Broken', ContentStatus::Published);

        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $crawler = $this->client->submitForm('Save changes', ['page[blocks]' => $this->json([
            ['type' => 'video', 'data' => ['url' => 'https://example.com/film.mp4']],
            ['type' => 'map', 'data' => ['location' => ['lat' => 123, 'lon' => 0]]],
            ['type' => 'gallery', 'data' => ['images' => []]],
            ['type' => 'accordion', 'data' => ['items' => []]],
            ['type' => 'code', 'data' => ['code' => "  \n"]],
        ])]);

        self::assertResponseStatusCodeSame(422);
        $config = json_decode((string) $crawler->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);
        self::assertSame('This does not look like a link to a YouTube or Vimeo video.', $config['errors']['0:url']);
        self::assertSame(['0:url', '1:location', '2:images', '3:items', '4:code'], array_keys($config['errors']));
    }

    public function testEditorReceivesFieldDefinitionsOfNestedItems(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Config', ContentStatus::Published);

        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $config = json_decode((string) $crawler->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);
        $types = array_column($config['types'], null, 'type');

        self::assertSame(['question', 'answer'], array_column($types['accordion']['fields'][0]['fields'], 'name'));
        self::assertSame('images', $types['gallery']['fields'][0]['type']);
        self::assertSame('location', $types['map']['fields'][0]['type']);
        self::assertSame('code', $types['code']['fields'][0]['type']);
    }
}
