<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The order of categories: what the server stores when one is dragged, and where the order shows.
 * Dragging itself is covered by tests/Browser/CategoryOrderDragDropTest.
 */
final class CategoryOrderTest extends AppWebTestCase
{
    private ?string $token = null;

    public function testCategoriesAreListedToBeArrangedAndNewOnesGoToTheEnd(): void
    {
        $this->loginAs(UserRole::Editor);
        $this->createCategory('News');
        $this->createCategory('Guides');

        $crawler = $this->client->request('GET', '/admin/categories');
        self::assertResponseIsSuccessful();
        $list = $crawler->filter('ul[data-controller="sortable"]');
        self::assertSame('/admin/categories/move', $list->attr('data-sortable-url-value'));
        self::assertNotSame('', $list->attr('data-sortable-csrf-value'));
        self::assertSame(['Guides', 'News'], $this->shown($crawler), 'Categories that were never arranged come by name.');
        self::assertCount(2, $list->filter('[data-dnd-item] > [data-dnd-handle]'));

        // Once there is an order, a new category does not jump into the middle of it.
        $this->move('News', 0);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/categories');
        $this->client->submitForm('Add category', ['category[name]' => 'Behind the scenes']);
        self::assertResponseRedirects('/admin/categories');
        self::assertSame(['News', 'Guides', 'Behind the scenes'], $this->shown($this->client->followRedirect()));
    }

    public function testMovingACategoryStoresTheOrderOfItsLanguage(): void
    {
        $this->loginAs(UserRole::Editor);
        $this->enableLocales('en', 'de');
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $this->createCategory($name);
        }
        $german = $this->createCategory('Neues', 'de');

        $this->move('Gamma', 0);
        self::assertResponseIsSuccessful();
        self::assertSame(['Gamma', 'Alpha', 'Beta'], $this->names(json_decode((string) $this->client->getResponse()->getContent(), true)['order']));

        // A position beyond the end means "last".
        $this->move('Gamma', 99);
        self::assertResponseIsSuccessful();
        self::assertSame(['Alpha', 'Beta', 'Gamma'], $this->shown($this->client->request('GET', '/admin/categories')));

        $this->move('Beta', 0);
        self::assertSame(['Beta', 'Alpha', 'Gamma'], $this->shown($this->client->request('GET', '/admin/categories')));

        // The other language keeps its own order and was not touched.
        $this->em()->clear();
        self::assertSame(0, $this->entity(Category::class, $german->getId())->getPosition());
        self::assertSame(['Neues'], $this->shown($this->client->request('GET', '/admin/categories?locale=de')));
    }

    public function testTheOrderShowsOnTheBlogInThePostFormAndInTheApi(): void
    {
        $admin = $this->loginAs(UserRole::Admin);
        $news = $this->createCategory('News');
        $guides = $this->createCategory('Guides');
        $this->createPost('First', $admin, $news);
        $post = $this->createPost('Second', $admin, $guides);

        $names = fn (): array => $this->client->request('GET', '/blog')->filter('nav[aria-label="Categories"] a')->each(static fn ($link): string => trim($link->text()));
        $before = $names();
        self::assertContains('Guides', $before);
        self::assertLessThan(array_search('News', $before, true), array_search('Guides', $before, true));

        $this->move('News', 0);
        self::assertResponseIsSuccessful();
        $after = $names();
        self::assertLessThan(array_search('Guides', $after, true), array_search('News', $after, true), 'The blog lists the categories in the order they were dragged into.');

        $options = $this->client->request('GET', '/admin/posts/'.$post->getId().'/edit')->filter('#post_category option')->each(static fn ($option): string => $option->text());
        self::assertSame(['News', 'Guides'], \array_slice($options, 1));
    }

    public function testMovesThatMakeNoSenseAreRefused(): void
    {
        $this->loginAs(UserRole::Editor);
        $news = $this->createCategory('News');
        $guides = $this->createCategory('Guides');

        // Categories are a plain list: one cannot be moved into another.
        $this->moveRaw(['id' => $news->getId(), 'parent' => $guides->getId(), 'position' => 0]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('The destination does not exist any more. Please reload the page.', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);

        $this->moveRaw(['id' => 9999, 'parent' => null, 'position' => 0]);
        self::assertResponseStatusCodeSame(404);
        $this->moveRaw(['id' => $news->getId(), 'position' => -1]);
        self::assertResponseStatusCodeSame(400);
        $this->moveRaw(['what' => 'ever']);
        self::assertResponseStatusCodeSame(400);

        $this->em()->clear();
        self::assertSame([0, 0], [$this->entity(Category::class, $news->getId())->getPosition(), $this->entity(Category::class, $guides->getId())->getPosition()]);
    }

    public function testOnlyEditorsAndAdminsArrangeCategoriesAndOnlyWithAToken(): void
    {
        $news = $this->createCategory('News');
        $this->createCategory('Guides');
        $body = $this->json(['id' => $news->getId(), 'parent' => null, 'position' => 1]);
        $post = fn (string $token) => $this->client->request('POST', '/admin/categories/move', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $body);

        $post('none');
        self::assertResponseRedirects('/login');

        $this->loginAs(UserRole::Editor);
        $post('forged');
        self::assertResponseStatusCodeSame(403);

        // Authors write posts but do not manage categories, not even with the token of their own session.
        $this->client->loginUser($this->createUser(UserRole::Author));
        $this->client->request('GET', '/admin/categories');
        self::assertResponseStatusCodeSame(403);
        $post($this->csrfToken('/admin/media', '[data-dropzone-csrf-value]', 'data-dropzone-csrf-value'));
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertSame(0, $this->entity(Category::class, $news->getId())->getPosition());
    }

    private function move(string $name, int $position): void
    {
        $category = $this->em()->getRepository(Category::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Category::class, $category);
        $this->moveRaw(['id' => $category->getId(), 'parent' => null, 'position' => $position]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function moveRaw(array $body): void
    {
        $this->token ??= $this->csrfToken('/admin/categories', '[data-sortable-csrf-value]', 'data-sortable-csrf-value');
        $this->client->request('POST', '/admin/categories/move', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], content: $this->json($body));
    }

    /**
     * @return list<string>
     */
    private function shown(Crawler $crawler): array
    {
        return $crawler->filter('.sort-list__title')->each(static fn ($link): string => $link->text());
    }

    /**
     * @param list<int> $ids
     *
     * @return list<string>
     */
    private function names(array $ids): array
    {
        $this->em()->clear();

        return array_map(fn (int $id): string => $this->entity(Category::class, $id)->getName(), $ids);
    }
}
