<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class PageEditingTest extends AppWebTestCase
{
    public function testEditorCreatesAndPublishesPage(): void
    {
        $this->loginAs(UserRole::Editor);

        // Step 1: only the title is asked for.
        $this->client->request('GET', '/admin/pages/new');
        $this->client->submitForm('Continue', ['content[title]' => 'About our Team']);

        $page = $this->em()->getRepository(Page::class)->findOneBy(['slug' => 'about-our-team']);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame(ContentStatus::Draft, $page->getStatus());
        self::assertResponseRedirects('/admin/pages/'.$page->getId().'/edit');

        // Step 2: the editor, with the block definitions for every type.
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $config = json_decode((string) $crawler->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);
        self::assertSame(
            ['heading', 'text', 'image', 'button', 'quote', 'divider', 'columns'],
            \array_slice(array_column($config['types'], 'type'), 0, 7),
        );

        $this->client->submitForm('Publish', [
            'page[title]' => 'About our Team',
            'page[blocks]' => json_encode([
                ['type' => 'heading', 'data' => ['text' => 'Who we are', 'level' => '2']],
                ['type' => 'text', 'data' => ['content' => '<p>We build <strong>things</strong>.</p>']],
                ['type' => 'columns', 'data' => [
                    'left' => [['type' => 'quote', 'data' => ['text' => 'Simple is better.', 'author' => 'Ada']]],
                    'right' => [['type' => 'button', 'data' => ['label' => 'Contact', 'url' => '/contact', 'style' => 'primary']]],
                ]],
            ]),
        ]);
        self::assertResponseRedirects('/admin/pages/'.$page->getId().'/edit');

        $this->em()->clear();
        $page = $this->entity(Page::class, $page->getId());
        self::assertSame(ContentStatus::Published, $page->getStatus());
        self::assertCount(3, $page->getBlocks());

        // The public page shows the rendered blocks.
        $this->client->request('GET', '/about-our-team');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'About our Team');
        self::assertSelectorTextSame('h2.block-heading', 'Who we are');
        self::assertSelectorTextContains('.block-text strong', 'things');
        self::assertSelectorTextContains('.block-columns .block-quote', 'Simple is better.');
        self::assertSelectorExists('.block-columns a.button--primary[href="/contact"]');
    }

    public function testDangerousContentIsRemovedWhenSaving(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Unsafe', ContentStatus::Published);

        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submitForm('Save changes', [
            'page[blocks]' => json_encode([
                ['type' => 'text', 'data' => ['content' => '<p onclick="steal()">Hello <script>alert(1)</script><a href="javascript:alert(1)">link</a><img src=x onerror=alert(1)></p>']],
                ['type' => 'button', 'data' => ['label' => 'Click', 'url' => 'javascript:alert(1)', 'style' => 'evil']],
                ['type' => 'heading', 'data' => ['text' => '<b>Bold?</b>', 'level' => '1', 'unknown' => 'dropped']],
                ['type' => 'does-not-exist', 'data' => ['x' => 'y']],
                ['type' => 'columns', 'data' => ['left' => [['type' => 'columns', 'data' => []]], 'right' => []]],
            ]),
        ]);

        $this->em()->clear();
        $blocks = $this->entity(Page::class, $page->getId())->getBlocks();

        self::assertCount(4, $blocks, 'The unknown block type must be dropped.');
        self::assertStringNotContainsString('script', $blocks[0]['data']['content']);
        self::assertStringNotContainsString('onclick', $blocks[0]['data']['content']);
        self::assertStringNotContainsString('javascript:', $blocks[0]['data']['content']);
        self::assertStringNotContainsString('<img', $blocks[0]['data']['content']);
        self::assertSame('', $blocks[1]['data']['url']);
        self::assertSame('primary', $blocks[1]['data']['style']);
        self::assertSame(['text' => '<b>Bold?</b>', 'level' => '2'], $blocks[2]['data']);
        self::assertSame([], $blocks[3]['data']['left'], 'Columns cannot be nested into columns.');

        // Plain text fields are escaped on output, never interpreted as HTML.
        $this->client->request('GET', '/unsafe');
        self::assertSelectorTextSame('h2.block-heading', '<b>Bold?</b>');
    }

    public function testDraftsAreOnlyVisibleToLoggedInUsers(): void
    {
        $this->createPage('Secret Plans', ContentStatus::Draft);

        $this->client->request('GET', '/secret-plans');
        self::assertResponseStatusCodeSame(404);

        $this->loginAs(UserRole::Author);
        $this->client->request('GET', '/secret-plans');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.edit-bar__draft');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
    }

    public function testOnlyOnePageCanBeTheHomepage(): void
    {
        $this->loginAs(UserRole::Editor);
        $first = $this->createPage('First Home', ContentStatus::Published, homepage: true);
        $second = $this->createPage('Second Home', ContentStatus::Published);

        $this->client->request('GET', '/admin/pages/'.$second->getId().'/edit');
        $this->client->submitForm('Save changes', ['page[homepage]' => '1', 'page[blocks]' => '[]']);

        $this->em()->clear();
        self::assertFalse($this->entity(Page::class, $first->getId())->isHomepage());
        self::assertTrue($this->entity(Page::class, $second->getId())->isHomepage());

        $this->client->request('GET', '/');
        self::assertSelectorTextSame('h1', 'Second Home');

        // The homepage lives at "/" only.
        $this->client->request('GET', '/second-home');
        self::assertResponseRedirects('/', 301);
    }

    public function testSlugIsCleanedUpAndMustBeUnique(): void
    {
        $this->loginAs(UserRole::Editor);
        $this->createPage('Contact', ContentStatus::Published);
        $page = $this->createPage('Other', ContentStatus::Published);

        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submitForm('Save changes', ['page[slug]' => 'Über Uns!', 'page[blocks]' => '[]']);
        $this->em()->clear();
        self::assertSame('uber-uns', $this->entity(Page::class, $page->getId())->getSlug());

        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submitForm('Save changes', ['page[slug]' => 'contact', 'page[blocks]' => '[]']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__errors', 'already in use');

        $this->client->submitForm('Save changes', ['page[slug]' => 'admin', 'page[blocks]' => '[]']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__errors', 'reserved');
    }

    public function testAutosaveKeepsUnsavedWorkWithoutPublishingIt(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Stable', ContentStatus::Published);
        $editUrl = '/admin/pages/'.$page->getId().'/edit';
        $token = $this->editorConfig($editUrl)['csrf'];

        $this->client->request('POST', '/admin/pages/'.$page->getId().'/autosave', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: $this->json([
            'fields' => ['page[title]' => 'Work in progress', 'page[_token]' => 'must-not-be-stored'],
            'blocks' => [['type' => 'text', 'data' => ['content' => '<p>Draft text<script>x</script></p>']]],
        ]));
        self::assertResponseIsSuccessful();

        // The published content is untouched ...
        $this->client->request('GET', '/stable');
        self::assertSelectorTextSame('h1', 'Stable');
        self::assertSelectorNotExists('.block-text');

        // ... and the editor offers the backup, sanitized like any other content.
        $config = $this->editorConfig($editUrl);
        self::assertSame(['page[title]' => 'Work in progress'], $config['autosave']['fields']);
        self::assertSame('<p>Draft text</p>', $config['autosave']['blocks'][0]['data']['content']);

        // Discarding removes it again.
        $this->client->request('POST', '/admin/pages/'.$page->getId().'/autosave/discard', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->editorConfig($editUrl)['autosave']);
    }

    public function testAutosaveRequiresCsrfToken(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Stable', ContentStatus::Published);

        $this->client->request('POST', '/admin/pages/'.$page->getId().'/autosave', content: '{}');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLivePreviewRendersUnsavedBlocksWithThePublicTheme(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Stable', ContentStatus::Published);
        $token = $this->editorConfig('/admin/pages/'.$page->getId().'/edit')['csrf'];

        $this->client->request('POST', '/admin/preview', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: $this->json([
            'title' => 'Preview title',
            'blocks' => [['type' => 'heading', 'data' => ['text' => 'Fresh heading', 'level' => '3']]],
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Preview title');
        self::assertSelectorTextSame('h3.block-heading', 'Fresh heading');
        self::assertSelectorExists('link[rel="stylesheet"][href*="site"]');
    }

    public function testAuthorsCannotManagePages(): void
    {
        $this->loginAs(UserRole::Author);
        $page = $this->createPage('Stable', ContentStatus::Published);

        foreach (['/admin/pages', '/admin/pages/new', '/admin/pages/'.$page->getId().'/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->client->request('POST', '/admin/pages/'.$page->getId().'/delete');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/pages/'.$page->getId().'/autosave', content: '{}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEditorDeletesPage(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Old News', ContentStatus::Published);
        $id = $page->getId();

        $token = $this->csrfToken('/admin/pages');
        $this->client->request('POST', '/admin/pages/'.$id.'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/admin/pages');
        $this->em()->clear();
        self::assertNull($this->em()->find(Page::class, $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function editorConfig(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode((string) $crawler->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);
    }
}
