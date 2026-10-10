<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\EventSubscriber\ApiSubscriber;
use App\Service\PageTree;
use App\Service\Settings;
use App\Tests\AppWebTestCase;

/**
 * Child pages: what the server stores when a page is moved in the tree, which moves it refuses,
 * and what happens to the addresses. Dragging itself is covered by tests/Browser/PageTreeDragDropTest.
 */
final class PageTreeTest extends AppWebTestCase
{
    private ?string $token = null;

    public function testTreeShowsPagesWithTheirChildPagesAndAddresses(): void
    {
        $user = $this->loginAs(UserRole::Editor);
        $about = $this->createPage('About', ContentStatus::Published);
        $team = $this->createPage('Team', ContentStatus::Published);
        $team->setParent($about);
        $home = $this->createPage('Home', ContentStatus::Published, homepage: true);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/admin/pages');
        self::assertResponseIsSuccessful();

        $tree = $crawler->filter('[data-controller="tree page-tree"]');
        self::assertSame('/admin/pages/move', $tree->attr('data-page-tree-url-value'));
        self::assertSame((string) PageTree::MAX_DEPTH, $tree->attr('data-tree-max-depth-value'));
        self::assertSame('pages-'.$user->getId().'-en', $tree->attr('data-tree-storage-value'), 'Collapsed branches are remembered per user and language.');

        $child = $crawler->filter('[data-dnd-id="'.$about->getId().'"] > ul[data-tree-parent="'.$about->getId().'"] > [data-dnd-id="'.$team->getId().'"]');
        self::assertCount(1, $child);
        self::assertSame('/about/team', trim($child->filter('[data-page-path]')->text()));
        self::assertSame('Child pages of “About”', $child->closest('ul')?->attr('aria-label'));

        // The homepage stays on the top level and offers no place for child pages.
        $homeItem = $crawler->filter('[data-dnd-id="'.$home->getId().'"]');
        self::assertNotNull($homeItem->attr('data-tree-top-only'));
        self::assertCount(0, $homeItem->filter('ul'));
    }

    public function testMovingADraftBelowAnotherPageNeedsNoConfirmation(): void
    {
        $this->loginAs(UserRole::Editor);
        $about = $this->createPage('About', ContentStatus::Published);
        $team = $this->createPage('Team', ContentStatus::Draft);

        $this->move($team, $about, 0);
        self::assertResponseIsSuccessful();
        self::assertEquals(['paths' => [$team->getId() => '/about/team'], 'redirects' => 0], $this->answer());

        $this->em()->clear();
        $team = $this->entity(Page::class, $team->getId());
        self::assertSame($about->getId(), $team->getParent()?->getId());
        self::assertSame('about/team', $team->getPath());
    }

    public function testMovingAPublishedPageAsksFirstAndThenRedirectsItAndItsChildren(): void
    {
        $this->loginAs(UserRole::Editor);
        $company = $this->createPage('Company', ContentStatus::Published);
        $about = $this->createPage('About', ContentStatus::Published);
        $team = $this->createPage('Team', ContentStatus::Published);
        $team->setParent($about);
        $notes = $this->createPage('Notes', ContentStatus::Draft);
        $notes->setParent($about);
        $this->em()->flush();

        // Without confirmation nothing moves; the answer lists what would change.
        $this->move($about, $company, 0);
        self::assertResponseStatusCodeSame(409);
        $answer = $this->answer();
        self::assertStringContainsString('addresses of 2 published pages', $answer['confirm']);
        self::assertSame([['/about', '/company/about'], ['/about/team', '/company/about/team']], array_map(static fn (array $change): array => [$change['from'], $change['to']], $answer['changes']), 'The draft has no public address that could break.');
        $this->em()->clear();
        self::assertNull($this->entity(Page::class, $about->getId())->getParent());

        $this->move($about, $company, 0, confirmed: true);
        self::assertResponseIsSuccessful();
        $answer = $this->answer();
        self::assertSame(2, $answer['redirects']);
        self::assertEquals([
            $about->getId() => '/company/about',
            $team->getId() => '/company/about/team',
            $notes->getId() => '/company/about/notes',
        ], $answer['paths']);

        $this->client->request('GET', '/company/about/team');
        self::assertResponseIsSuccessful();
        foreach (['/about' => '/company/about', '/about/team' => '/company/about/team'] as $old => $new) {
            $this->client->request('GET', $old);
            self::assertResponseRedirects($new, 301);
        }
        // A child page of a page whose address looks like a language code works as well.
        $uk = $this->createPage('UK', ContentStatus::Published);
        $this->createPage('Offices', ContentStatus::Published)->setParent($uk);
        $this->em()->flush();
        $this->client->request('GET', '/uk/offices');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Offices');

        // The last part of an address alone is not an address.
        $this->client->request('GET', '/team');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSiblingsAreReorderedWithoutChangingAnyAddress(): void
    {
        $this->loginAs(UserRole::Editor);
        $this->createPage('First', ContentStatus::Published);
        $this->createPage('Second', ContentStatus::Published);
        $third = $this->createPage('Third', ContentStatus::Published);

        $this->move($third, null, 0);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->answer()['redirects']);

        $titles = $this->client->request('GET', '/admin/pages')->filter('.page-tree > li > .page-tree__row .page-tree__title')->each(static fn ($link): string => $link->text());
        self::assertSame(['Third', 'First', 'Second'], $titles);
    }

    public function testPagesThatWereNeverArrangedKeepTheOrderTheyAreShownIn(): void
    {
        $this->loginAs(UserRole::Editor);
        // Created in another order than the tree shows them in (by title, as long as nobody arranged them).
        $zebra = $this->createPage('Zebra', ContentStatus::Draft);
        $this->createPage('Mango', ContentStatus::Draft);
        $alpha = $this->createPage('Alpha', ContentStatus::Draft);
        $titles = fn (): array => $this->client->request('GET', '/admin/pages')->filter('.page-tree > li > .page-tree__row .page-tree__title')->each(static fn ($link): string => $link->text());
        self::assertSame(['Alpha', 'Mango', 'Zebra'], $titles());

        // "Zebra" goes between the other two: those stay where they were.
        $this->move($zebra, null, 1);
        self::assertResponseIsSuccessful();
        self::assertSame(['Alpha', 'Zebra', 'Mango'], $titles());

        // A page that leaves the level does not shuffle the ones that stay.
        $this->move($zebra, $alpha, 0);
        self::assertResponseIsSuccessful();
        self::assertSame(['Alpha', 'Mango'], $titles());
    }

    public function testMovesThatBreakTheTreeAreRefused(): void
    {
        $this->loginAs(UserRole::Editor);
        $this->enableLocales('en', 'de');
        $home = $this->createPage('Home', ContentStatus::Published, homepage: true);
        $about = $this->createPage('About', ContentStatus::Draft);
        $team = $this->createPage('Team', ContentStatus::Draft);
        $team->setParent($about);
        $german = $this->createPage('Kontakt', ContentStatus::Draft, locale: 'de');
        $this->em()->flush();

        // Into its own child page.
        $this->move($about, $team, 0);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Something cannot be moved into itself or into one of its own sub-items.', $this->answer()['error']);

        // Into itself.
        $this->move($about, $about, 0);
        self::assertResponseStatusCodeSame(422);

        // The homepage is nobody's child and nobody's parent.
        $this->move($home, $about, 0);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('The homepage stays on the top level and has no child pages.', $this->answer()['error']);
        $this->move($team, $home, 0);
        self::assertResponseStatusCodeSame(422);

        // Every language has its own tree.
        $this->move($german, $about, 0);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('A page can only belong to a page of the same language.', $this->answer()['error']);

        // A parent that does not exist.
        $this->moveRaw(['id' => $team->getId(), 'parent' => 9999, 'position' => 0]);
        self::assertResponseStatusCodeSame(422);

        // A page that does not exist, and a body that is no move at all.
        $this->moveRaw(['id' => 9999, 'parent' => null, 'position' => 0]);
        self::assertResponseStatusCodeSame(404);
        $this->moveRaw(['id' => 'about', 'position' => -1]);
        self::assertResponseStatusCodeSame(400);

        // Nothing of all that was stored.
        $this->em()->clear();
        self::assertNull($this->entity(Page::class, $about->getId())->getParent());
        self::assertNull($this->entity(Page::class, $home->getId())->getParent());
        self::assertNull($this->entity(Page::class, $german->getId())->getParent());
        self::assertSame($about->getId(), $this->entity(Page::class, $team->getId())->getParent()?->getId());
    }

    public function testPagesCannotBeNestedDeeperThanTheLimit(): void
    {
        $this->loginAs(UserRole::Editor);
        $parent = null;
        $chain = [];
        for ($level = 1; $level <= PageTree::MAX_DEPTH; ++$level) {
            $page = $this->createPage('Level '.$level, ContentStatus::Draft);
            $page->setParent($parent);
            $chain[] = $parent = $page;
        }
        $extra = $this->createPage('Extra', ContentStatus::Draft);
        $child = $this->createPage('Child', ContentStatus::Draft);
        $child->setParent($extra);
        $this->em()->flush();

        // Below the deepest page there is no room ...
        $this->move($extra, $chain[PageTree::MAX_DEPTH - 1], 0);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('That would be nested too deeply.', $this->answer()['error']);

        // ... and one level higher there is none either, because the page brings a child along.
        $this->move($extra, $chain[PageTree::MAX_DEPTH - 2], 0);
        self::assertResponseStatusCodeSame(422);

        $this->move($extra, $chain[PageTree::MAX_DEPTH - 3], 0);
        self::assertResponseIsSuccessful();
    }

    public function testOnlyEditorsAndAdminsMovePagesAndOnlyWithAToken(): void
    {
        $about = $this->createPage('About', ContentStatus::Draft);
        $team = $this->createPage('Team', ContentStatus::Draft);
        $body = ['id' => $team->getId(), 'parent' => $about->getId(), 'position' => 0];

        // Not logged in.
        $this->client->request('POST', '/admin/pages/move', server: ['CONTENT_TYPE' => 'application/json'], content: $this->json($body));
        self::assertResponseRedirects('/login');

        // Logged in as an editor, but without the token of the admin area.
        $this->loginAs(UserRole::Editor);
        $this->client->request('POST', '/admin/pages/move', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'wrong'], content: $this->json($body));
        self::assertResponseStatusCodeSame(403);

        // Authors do not manage pages, not even with a valid token of their own session.
        $author = $this->createUser(UserRole::Author);
        $this->client->loginUser($author);
        $this->client->request('GET', '/admin/pages');
        self::assertResponseStatusCodeSame(403);
        $authorToken = $this->csrfToken('/admin/media', '[data-dropzone-csrf-value]', 'data-dropzone-csrf-value');
        $this->client->request('POST', '/admin/pages/move', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $authorToken], content: $this->json($body));
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertNull($this->entity(Page::class, $team->getId())->getParent());
    }

    public function testDeletingAPageMovesItsChildPagesUpAndRedirectsThem(): void
    {
        $this->loginAs(UserRole::Editor);
        $about = $this->createPage('About', ContentStatus::Published);
        $team = $this->createPage('Team', ContentStatus::Published);
        $team->setParent($about);
        $this->em()->flush();

        $token = $this->csrfToken('/admin/pages', '[data-page-tree-csrf-value]', 'data-page-tree-csrf-value');
        $this->client->request('POST', '/admin/pages/'.$about->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/pages');

        $this->em()->clear();
        self::assertNull($this->entity(Page::class, $team->getId())->getParent());
        $this->client->request('GET', '/team');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/about/team');
        self::assertResponseRedirects('/team', 301);
    }

    public function testRenamingTheAddressOfAParentRedirectsItsChildPages(): void
    {
        $this->loginAs(UserRole::Editor);
        $about = $this->createPage('About', ContentStatus::Published);
        $team = $this->createPage('Team', ContentStatus::Published);
        $team->setParent($about);
        $this->em()->flush();

        $this->client->request('GET', '/admin/pages/'.$about->getId().'/edit');
        $this->client->submitForm('Save', ['page[slug]' => 'company']);
        self::assertResponseRedirects();

        $this->client->request('GET', '/company/team');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/about/team');
        self::assertResponseRedirects('/company/team', 301);
    }

    public function testAPageWithChildPagesCannotBecomeTheHomepage(): void
    {
        $this->loginAs(UserRole::Editor);
        $about = $this->createPage('About', ContentStatus::Published);
        $team = $this->createPage('Team', ContentStatus::Published);
        $team->setParent($about);
        $this->em()->flush();

        $this->client->request('GET', '/admin/pages/'.$about->getId().'/edit');
        $crawler = $this->client->submitForm('Save', ['page[homepage]' => true]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The homepage stays on the top level and has no child pages.', $crawler->filter('body')->text());
        $this->em()->clear();
        self::assertFalse($this->entity(Page::class, $about->getId())->isHomepage());
    }

    public function testApiTellsParentAndFullPath(): void
    {
        $about = $this->createPage('About', ContentStatus::Published);
        $team = $this->createPage('Team', ContentStatus::Published);
        $team->setParent($about);
        $this->em()->flush();
        static::getContainer()->get(Settings::class)->set(ApiSubscriber::ENABLED_SETTING, true);

        $this->client->request('GET', '/api/v1/pages/'.$team->getId());
        self::assertResponseIsSuccessful();
        $data = $this->answer();
        $page = $data['data'] ?? $data;
        self::assertSame('about/team', $page['path']);
        self::assertSame($about->getId(), $page['parent']);
    }

    private function move(Page $page, ?Page $parent, int $position, bool $confirmed = false): void
    {
        $this->moveRaw(['id' => $page->getId(), 'parent' => $parent?->getId(), 'position' => $position] + ($confirmed ? ['confirmed' => true] : []));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function moveRaw(array $body): void
    {
        $this->token ??= $this->csrfToken('/admin/pages', '[data-page-tree-csrf-value]', 'data-page-tree-csrf-value');
        $this->client->request('POST', '/admin/pages/move', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], content: $this->json($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
    }
}
