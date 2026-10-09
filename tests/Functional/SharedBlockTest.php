<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Entity\SharedBlock;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class SharedBlockTest extends AppWebTestCase
{
    public function testBlockIsSavedAsSharedBlockAndShownOnSeveralPages(): void
    {
        $this->loginAs(UserRole::Editor);
        $first = $this->createPage('First', ContentStatus::Published);
        $second = $this->createPage('Second', ContentStatus::Published);

        // "Save as shared block" in the editor sends one block and a name.
        $config = $this->editorConfig('/admin/pages/'.$first->getId().'/edit');
        self::assertSame('/admin/shared-blocks', $config['urls']['sharedCreate']);
        $this->client->request('POST', $config['urls']['sharedCreate'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $config['csrf']], content: $this->json([
            'name' => 'Opening hours',
            'block' => ['type' => 'text', 'data' => ['content' => '<p>Open <strong>daily</strong><script>x</script></p>']],
        ]));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Opening hours', $created['name']);

        $shared = $this->entity(SharedBlock::class, $created['id']);
        self::assertSame('<p>Open <strong>daily</strong></p>', $shared->getBlocks()[0]['data']['content'], 'Shared content is sanitized like any other.');

        // Both pages show it by reference.
        foreach ([$first, $second] as $page) {
            $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
            $this->client->submitForm('Save changes', ['page[blocks]' => $this->json([['type' => 'shared', 'data' => ['block' => $created['id']]]])]);
            self::assertResponseRedirects();
        }
        $this->client->request('GET', '/second');
        self::assertSelectorTextContains('.block-text', 'Open daily');

        // The list and the edit screen say where it is used - before anything is changed.
        $this->client->request('GET', '/admin/shared-blocks');
        self::assertSelectorTextContains('tbody td', '2 pages or posts');
        $crawler = $this->client->request('GET', '/admin/shared-blocks/'.$created['id'].'/edit');
        self::assertSame(['First', 'Second'], $crawler->filter('.editor__notice a')->each(static fn ($a) => $a->text()));

        // Editing it in one place changes every page.
        $this->client->submitForm('Save', [
            'shared_block[name]' => 'Opening hours',
            'shared_block[blocks]' => $this->json([['type' => 'text', 'data' => ['content' => '<p>Closed on Sundays</p>']]]),
        ]);
        self::assertResponseRedirects('/admin/shared-blocks/'.$created['id'].'/edit');
        foreach (['/first', '/second'] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorTextContains('.block-text', 'Closed on Sundays');
        }

        // Deleting it: pages keep working and simply no longer show it.
        $token = $this->csrfToken('/admin/shared-blocks');
        $this->client->request('POST', '/admin/shared-blocks/'.$created['id'].'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/shared-blocks');
        $this->client->request('GET', '/first');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.block-text');
    }

    public function testSharedBlocksCannotContainSharedBlocks(): void
    {
        $this->loginAs(UserRole::Editor);
        $inner = (new SharedBlock())->setName('Inner')->setBlocks([['type' => 'divider', 'data' => []]]);
        $this->em()->persist($inner);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/admin/shared-blocks/new');
        $config = json_decode((string) $crawler->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);
        self::assertNotContains('shared', array_column($config['types'], 'type'), 'The editor of a shared block does not offer shared blocks.');
        self::assertArrayNotHasKey('autosave', $config['urls']);

        $this->client->submitForm('Save', [
            'shared_block[name]' => 'Outer',
            'shared_block[blocks]' => $this->json([
                ['type' => 'shared', 'data' => ['block' => $inner->getId()]],
                ['type' => 'heading', 'data' => ['text' => 'Kept', 'level' => '2']],
            ]),
        ]);

        $outer = $this->em()->getRepository(SharedBlock::class)->findOneBy(['name' => 'Outer']);
        self::assertInstanceOf(SharedBlock::class, $outer);
        self::assertSame(['heading'], array_column($outer->getBlocks(), 'type'));
    }

    public function testReferenceWithoutChosenBlockIsRejected(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Page', ContentStatus::Published);

        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submitForm('Save changes', ['page[blocks]' => $this->json([['type' => 'shared', 'data' => ['block' => null]]])]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->entity(Page::class, $page->getId())->getBlocks());
    }

    public function testAuthorsMayUseButNotManageSharedBlocks(): void
    {
        $shared = (new SharedBlock())->setName('Footer note')->setBlocks([['type' => 'divider', 'data' => []]]);
        $this->em()->persist($shared);
        $this->em()->flush();

        $author = $this->loginAs(UserRole::Author);
        $post = $this->createPost('Mine', author: $author);

        // Inserting an existing shared block into their own post is fine ...
        $config = $this->editorConfig('/admin/posts/'.$post->getId().'/edit');
        self::assertSame(['Footer note'], array_column($config['sharedBlocks'], 'name'));
        self::assertNull($config['urls']['sharedCreate'], 'Authors are not offered "Save as shared block".');

        // ... creating, editing and deleting shared blocks is not.
        $this->client->request('GET', '/admin/shared-blocks');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/shared-blocks', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $config['csrf']], content: $this->json(['name' => 'X', 'block' => ['type' => 'divider', 'data' => []]]));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/shared-blocks/'.$shared->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/shared-blocks/'.$shared->getId().'/delete', ['_token' => $config['csrf']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreatingRequiresTokenAndName(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Page', ContentStatus::Published);
        $config = $this->editorConfig('/admin/pages/'.$page->getId().'/edit');
        $block = ['type' => 'divider', 'data' => []];

        $this->client->request('POST', '/admin/shared-blocks', server: ['CONTENT_TYPE' => 'application/json'], content: $this->json(['name' => 'X', 'block' => $block]));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/shared-blocks', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $config['csrf']], content: $this->json(['name' => ' ', 'block' => $block]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(SharedBlock::class)->count([]));
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
