<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Entity\Post;
use App\Entity\Revision;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class RevisionTest extends AppWebTestCase
{
    public function testEverySaveCreatesARevisionThatCanBePreviewedAndRestored(): void
    {
        $editor = $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/pages/new');
        $this->client->submitForm('Continue', ['content[title]' => 'Opening Hours']);
        $crawler = $this->client->followRedirect();
        $page = $this->em()->getRepository(Page::class)->findOneBy([]);
        self::assertInstanceOf(Page::class, $page);
        $id = (int) $page->getId();
        self::assertCount(0, $crawler->filter('[data-tab="history"]'), 'With a single version there is nothing to go back to, so no history tab.');

        $this->save($id, 'Opening Hours', 'Monday to Friday');
        $this->save($id, 'New Opening Hours', 'Open every day');

        $revisions = $this->em()->getRepository(Revision::class)->findBy(['contentType' => 'page', 'contentId' => $id], ['id' => 'ASC']);
        self::assertCount(3, $revisions);
        self::assertSame($editor->getId(), $revisions[1]->getAuthor()?->getId());
        self::assertSame('Monday to Friday', $revisions[1]->getBlocks()[0]['data']['text']);

        // The history lists the versions, newest first, with author and a marker for the current one.
        $crawler = $this->client->request('GET', '/admin/pages/'.$id.'/edit');
        self::assertSelectorExists('[data-tab="history"]');
        self::assertCount(3, $crawler->filter('.revision-list li'));
        self::assertSelectorTextContains('.revision-list li:first-child', 'Current version');
        self::assertSelectorTextContains('.revision-list li:nth-child(2)', 'Editor User');
        self::assertSelectorTextContains('.revision-list li:nth-child(2)', 'Opening Hours');

        // Preview of the earlier version in the public theme.
        $this->client->request('GET', '/admin/pages/'.$id.'/revisions/'.$revisions[1]->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Opening Hours');
        self::assertSelectorTextSame('h2.block-heading', 'Monday to Friday');
        self::assertSelectorTextContains('.edit-bar', 'Earlier version');

        // Restoring brings title and blocks back and is itself recorded as a new version.
        $button = $crawler->filter('.revision-list li:nth-child(2) button');
        $this->client->request('POST', (string) $button->attr('formaction'), ['_token' => $button->attr('value')]);
        self::assertResponseRedirects('/admin/pages/'.$id.'/edit');

        $this->em()->clear();
        $page = $this->entity(Page::class, $id);
        self::assertSame('Opening Hours', $page->getTitle());
        self::assertSame('Monday to Friday', $page->getBlocks()[0]['data']['text']);
        self::assertSame('opening-hours', $page->getSlug(), 'Restoring never changes the address.');
        self::assertSame(4, $this->em()->getRepository(Revision::class)->count(['contentId' => $id]));
    }

    public function testOldRevisionsAreRemovedBeyondTheLimit(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Busy Page', ContentStatus::Published);
        $limit = (int) static::getContainer()->getParameter('app.max_revisions');
        self::assertSame(5, $limit, 'The test environment keeps five revisions.');

        for ($i = 1; $i <= $limit + 3; ++$i) {
            $this->save((int) $page->getId(), 'Busy Page', 'Version '.$i);
        }

        $revisions = $this->em()->getRepository(Revision::class)->findBy(['contentId' => $page->getId()], ['id' => 'ASC']);
        self::assertCount($limit, $revisions);
        self::assertSame('Version 4', $revisions[0]->getBlocks()[0]['data']['text'], 'The oldest versions are the ones that go.');
        self::assertSame('Version 8', $revisions[$limit - 1]->getBlocks()[0]['data']['text']);
    }

    public function testRevisionsBelongToTheirContentAndRespectPermissions(): void
    {
        $editor = $this->createUser(UserRole::Editor);
        $this->client->loginUser($editor);
        $page = $this->createPage('Page', ContentStatus::Published);
        $other = $this->createPage('Other', ContentStatus::Published);
        $post = $this->createPost('Editors Post', author: $editor);
        $this->save((int) $page->getId(), 'Page', 'One');
        $this->client->request('GET', '/admin/posts/'.$post->getId().'/edit');
        $this->client->submitForm('Save changes', ['post[blocks]' => '[]']);

        $pageRevision = $this->em()->getRepository(Revision::class)->findOneBy(['contentType' => 'page']);
        $postRevision = $this->em()->getRepository(Revision::class)->findOneBy(['contentType' => 'post']);
        self::assertInstanceOf(Revision::class, $pageRevision);
        self::assertInstanceOf(Revision::class, $postRevision);
        $token = $this->csrfToken('/admin/pages');

        // A revision cannot be applied to different content, and restoring needs the token.
        $this->client->request('GET', '/admin/pages/'.$other->getId().'/revisions/'.$pageRevision->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/admin/pages/'.$other->getId().'/revisions/'.$pageRevision->getId().'/restore', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/admin/pages/'.$page->getId().'/revisions/'.$pageRevision->getId().'/restore', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        // Authors neither see nor restore versions of pages or of other people's posts.
        $this->loginAs(UserRole::Author);
        $this->client->request('GET', '/admin/pages/'.$page->getId().'/revisions/'.$pageRevision->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/posts/'.$post->getId().'/revisions/'.$postRevision->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/posts/'.$post->getId().'/revisions/'.$postRevision->getId().'/restore', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletingContentRemovesItsHistory(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Temporary', ContentStatus::Published);
        $this->save((int) $page->getId(), 'Temporary', 'One');
        self::assertSame(1, $this->em()->getRepository(Revision::class)->count([]));

        $token = $this->csrfToken('/admin/pages');
        $this->client->request('POST', '/admin/pages/'.$page->getId().'/delete', ['_token' => $token]);

        self::assertSame(0, $this->em()->getRepository(Revision::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Post::class)->count([]));
    }

    private function save(int $pageId, string $title, string $headingText): void
    {
        $this->client->request('GET', '/admin/pages/'.$pageId.'/edit');
        $this->client->submitForm($this->client->getCrawler()->selectButton('Save changes')->count() > 0 ? 'Save changes' : 'Save draft', [
            'page[title]' => $title,
            'page[blocks]' => $this->json([['type' => 'heading', 'data' => ['text' => $headingText, 'level' => '2']]]),
        ]);
        self::assertResponseRedirects();
    }
}
