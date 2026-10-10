<?php

namespace App\Tests\Functional;

use App\Entity\Redirect;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class RedirectTest extends AppWebTestCase
{
    public function testChangingTheAddressOfAPublishedPageCreatesARedirect(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Old Name', ContentStatus::Published);

        $this->changeSlug((int) $page->getId(), 'new-name');

        $redirect = $this->em()->getRepository(Redirect::class)->findOneBy([]);
        self::assertInstanceOf(Redirect::class, $redirect);
        self::assertSame(['/old-name', '/new-name', true], [$redirect->getSource(), $redirect->getTarget(), $redirect->isAutomatic()]);

        $this->client->request('GET', '/old-name');
        self::assertResponseRedirects('/new-name', 301);
        // A query string does not get in the way.
        $this->client->request('GET', '/old-name?utm=1');
        self::assertResponseRedirects('/new-name', 301);
    }

    public function testRenamingTwiceLeadsStraightToTheFinalAddress(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('First', ContentStatus::Published);
        $id = (int) $page->getId();

        $this->changeSlug($id, 'second');
        $this->changeSlug($id, 'third');

        self::assertSame(['/first' => '/third', '/second' => '/third'], $this->allRedirects(), 'No chain: both old addresses point to the current one.');

        // Going back to an earlier address makes that address live again instead of creating a loop.
        $this->changeSlug($id, 'first');
        self::assertSame(['/second' => '/first', '/third' => '/first'], $this->allRedirects());
        $this->client->request('GET', '/first');
        self::assertResponseIsSuccessful();
    }

    public function testDraftsAndUnchangedAddressesCreateNoRedirect(): void
    {
        $this->loginAs(UserRole::Editor);
        $draft = $this->createPage('Draft', ContentStatus::Draft);
        $published = $this->createPage('Published', ContentStatus::Published);

        $this->changeSlug((int) $draft->getId(), 'renamed-draft', 'Save draft');
        $this->client->request('GET', '/admin/pages/'.$published->getId().'/edit');
        $this->client->submitForm('Save changes', ['page[title]' => 'New title, same address', 'page[blocks]' => '[]']);

        self::assertSame([], $this->allRedirects());
    }

    public function testPostAddressesAndTheHomepageAreCovered(): void
    {
        $this->loginAs(UserRole::Editor);
        $post = $this->createPost('Old Post');
        $page = $this->createPage('Landing', ContentStatus::Published);

        $this->client->request('GET', '/admin/posts/'.$post->getId().'/edit');
        $this->client->submitForm('Save changes', ['post[slug]' => 'new-post', 'post[blocks]' => '[]']);

        // Making a page the homepage moves it from "/landing" to "/".
        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submitForm('Save changes', ['page[homepage]' => '1', 'page[blocks]' => '[]']);

        self::assertSame(['/blog/old-post' => '/blog/new-post', '/landing' => '/'], $this->allRedirects());
    }

    public function testAdminManagesRedirectsByHand(): void
    {
        $this->loginAs(UserRole::Admin);
        $this->createPage('Contact', ContentStatus::Published);

        $this->client->request('GET', '/admin/redirects');
        $this->client->submitForm('Save', ['source' => 'kontakt/', 'target' => '/contact']);
        self::assertResponseRedirects('/admin/redirects');
        $this->client->request('GET', '/admin/redirects');
        $this->client->submitForm('Save', ['source' => '/shop', 'target' => 'https://shop.example.com/start']);

        self::assertSame(['/kontakt' => '/contact', '/shop' => 'https://shop.example.com/start'], $this->allRedirects());
        $this->client->request('GET', '/kontakt');
        self::assertResponseRedirects('/contact', 301);
        $this->client->request('GET', '/shop');
        self::assertResponseRedirects('https://shop.example.com/start', 301);

        // Edit
        $redirect = $this->em()->getRepository(Redirect::class)->findOneBy(['source' => '/shop']);
        self::assertInstanceOf(Redirect::class, $redirect);
        $this->client->request('GET', '/admin/redirects?edit='.$redirect->getId());
        self::assertInputValueSame('source', '/shop');
        $this->client->submitForm('Save', ['source' => '/store', 'target' => '/contact']);
        self::assertSame(['/kontakt' => '/contact', '/store' => '/contact'], $this->allRedirects());

        // Delete
        $token = $this->csrfToken('/admin/redirects');
        $this->client->request('POST', '/admin/redirects/'.$redirect->getId().'/delete', ['_token' => $token]);
        self::assertSame(['/kontakt' => '/contact'], $this->allRedirects());
    }

    public function testLoopsAndChainsAreRefusedOrFlattened(): void
    {
        $this->loginAs(UserRole::Admin);
        $add = function (string $source, string $target): void {
            $this->client->request('GET', '/admin/redirects');
            $this->client->submitForm('Save', ['source' => $source, 'target' => $target]);
        };

        $add('/a', '/b');
        self::assertResponseRedirects('/admin/redirects');

        // A loop is refused ...
        $add('/b', '/a');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash--error', 'circle');
        $add('/c', '/c');
        self::assertResponseStatusCodeSame(422);

        // ... a chain is flattened in both directions.
        $add('/b', '/final');
        self::assertSame(['/a' => '/final', '/b' => '/final'], $this->allRedirects(), '"/a" used to end at "/b" and now goes to the final address directly.');
        $add('/z', '/a');
        self::assertSame('/final', $this->allRedirects()['/z'], 'A new redirect to an address that is redirected itself goes to the end directly.');

        // Other invalid input.
        foreach ([['/a', '/other', 'already'], ['https://evil.example/x', '/a', 'address on this website'], ['/admin/users', '/', 'address on this website'], ['/ok', 'javascript:alert(1)', 'destination']] as [$source, $target, $message]) {
            $add($source, $target);
            self::assertResponseStatusCodeSame(422, $source.' -> '.$target);
            self::assertSelectorTextContains('.flash--error', $message);
        }
    }

    public function testExistingPagesAreNeverHiddenByARedirect(): void
    {
        $this->loginAs(UserRole::Admin);
        $this->createPage('Alive', ContentStatus::Published);

        $this->client->request('GET', '/admin/redirects');
        $this->client->submitForm('Save', ['source' => '/alive', 'target' => '/elsewhere']);

        $this->client->request('GET', '/alive');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Alive');
    }

    public function testOnlyAdminsManageRedirects(): void
    {
        $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/redirects');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/redirects', ['source' => '/x', 'target' => '/y']);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs(UserRole::Admin);
        $this->client->request('POST', '/admin/redirects', ['source' => '/x', 'target' => '/y', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->allRedirects());
    }

    private function changeSlug(int $pageId, string $slug, string $button = 'Save changes'): void
    {
        $this->client->request('GET', '/admin/pages/'.$pageId.'/edit');
        $this->client->submitForm($button, ['page[slug]' => $slug, 'page[blocks]' => '[]']);
        self::assertResponseRedirects();
    }

    /**
     * @return array<string, string> source => target, sorted by source
     */
    private function allRedirects(): array
    {
        $this->em()->clear();
        $redirects = [];
        foreach ($this->em()->getRepository(Redirect::class)->findBy([], ['source' => 'ASC']) as $redirect) {
            $redirects[$redirect->getSource()] = $redirect->getTarget();
        }

        return $redirects;
    }
}
