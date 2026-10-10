<?php

namespace App\Tests\Functional;

use App\Entity\MenuItem;
use App\Entity\Page;
use App\Enum\ContentStatus;
use App\Enum\MenuItemType;
use App\Enum\MenuLocation;
use App\Enum\UserRole;
use App\EventSubscriber\ApiSubscriber;
use App\Service\Settings;
use App\Tests\AppWebTestCase;

final class ApiTest extends AppWebTestCase
{
    public function testApiIsOffUntilAnAdminSwitchesItOn(): void
    {
        $this->createPage('About', ContentStatus::Published);

        foreach (['/api/v1', '/api/v1/pages', '/api/v1/openapi.json', '/api/v1/unknown'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
            self::assertSame(['error' => ['status' => 404, 'message' => 'Not found.']], $this->response());
        }

        // Editors cannot switch it on.
        $this->loginAs(UserRole::Editor);
        $this->client->request('GET', '/admin/settings');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUser(UserRole::Admin));
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertCount(0, $crawler->filter('#settings_apiEnabled[checked]'));
        $this->client->submitForm('Save', ['settings[apiEnabled]' => true]);
        self::assertResponseRedirects('/admin/settings');
        $this->client->followRedirect();
        self::assertSelectorExists('a[href="/api/v1/openapi.json"]');

        $this->client->request('GET', '/api/v1/pages');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
        self::assertSame('About', $this->response()['data'][0]['title']);

        // ... and off again.
        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['settings[apiEnabled]' => false]);
        $this->client->request('GET', '/api/v1/pages');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPagesAreListedFilteredAndPaginated(): void
    {
        $this->enableApi();
        $this->enableLocales('en', 'de');
        $home = $this->createPage('Home', ContentStatus::Published, homepage: true);
        $about = $this->createPage('About', ContentStatus::Published);
        $this->createPage('Über uns', ContentStatus::Published, locale: 'de', translationOf: $about)->setSlug('ueber-uns');
        $this->createPage('Secret', ContentStatus::Draft);
        $this->em()->flush();

        $this->client->request('GET', '/api/v1/pages');
        $response = $this->response();
        self::assertSame(['About', 'Home', 'Über uns'], array_column($response['data'], 'title'), 'Drafts are not listed.');
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 3, 'pages' => 1], $response['meta']);
        self::assertNull($response['links']['next']);
        self::assertArrayNotHasKey('blocks', $response['data'][0], 'Lists do not carry the content.');
        self::assertSame('http://localhost/about', $response['data'][0]['url']);
        self::assertSame('http://localhost/', $response['data'][1]['url']);
        self::assertTrue($response['data'][1]['homepage']);
        self::assertSame([['locale' => 'de', 'id' => $response['data'][2]['id'], 'url' => 'http://localhost/de/ueber-uns']], $response['data'][0]['translations']);

        $this->client->request('GET', '/api/v1/pages?locale=de');
        self::assertSame(['Über uns'], array_column($this->response()['data'], 'title'));

        $this->client->request('GET', '/api/v1/pages?slug=about');
        self::assertSame([$about->getId()], array_column($this->response()['data'], 'id'));

        $this->client->request('GET', '/api/v1/pages?per_page=2&page=2&locale=');
        $response = $this->response();
        self::assertSame(['Über uns'], array_column($response['data'], 'title'));
        self::assertSame(['page' => 2, 'per_page' => 2, 'total' => 3, 'pages' => 2], $response['meta']);
        self::assertSame('http://localhost/api/v1/pages?page=1&per_page=2&locale=', $response['links']['prev']);
        self::assertNull($response['links']['next']);

        foreach (['/api/v1/pages?locale=fr', '/api/v1/pages?per_page=101', '/api/v1/pages?page=0'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(400, $path);
            self::assertSame(400, $this->response()['error']['status']);
        }

        self::assertNotNull($home->getId());
    }

    public function testDetailCarriesBlocksAndRenderedHtml(): void
    {
        $this->enableApi();
        $image = $this->createMedia('photo.png', 'A photo');
        $page = $this->createPage('Story', ContentStatus::Published);
        $page->setShareImage($image)->setBlocks([
            ['type' => 'heading', 'data' => ['text' => 'Chapter one', 'level' => '2']],
            ['type' => 'text', 'data' => ['content' => '<p>Once upon a <strong>time</strong>.</p>']],
            ['type' => 'image', 'data' => ['image' => $image->getId(), 'alt' => '', 'caption' => '']],
        ]);
        $draft = $this->createPage('Draft', ContentStatus::Draft);
        $this->em()->flush();

        $this->client->request('GET', '/api/v1/pages/'.$page->getId());
        self::assertResponseIsSuccessful();
        $data = $this->response()['data'];

        self::assertSame(['heading', 'text', 'image'], array_column($data['blocks'], 'type'));
        self::assertSame('Chapter one', $data['blocks'][0]['data']['text']);
        self::assertSame($image->getId(), $data['blocks'][2]['data']['image']);
        self::assertStringContainsString('<h2', $data['html']);
        self::assertStringContainsString('Once upon a <strong>time</strong>.', $data['html']);
        self::assertStringContainsString('photo', $data['html']);
        self::assertSame('http://localhost/uploads/media/photo.png', $data['share_image']['url']);
        self::assertSame('A photo', $data['share_image']['alt']);

        // Drafts are as invisible as pages that do not exist, also for logged-in users.
        $this->loginAs(UserRole::Admin);
        foreach ([$draft->getId(), 99999] as $id) {
            $this->client->request('GET', '/api/v1/pages/'.$id);
            self::assertResponseStatusCodeSame(404);
            self::assertSame('Not Found.', $this->response()['error']['message']);
        }
    }

    public function testPostsCategoriesMenusAndMedia(): void
    {
        $this->enableApi();
        $author = $this->createUser(UserRole::Author);
        $news = $this->createCategory('News');
        $tips = $this->createCategory('Tips');
        $cover = $this->createMedia('cover.png', 'Cover');
        $older = $this->createPost('Older post', $author, $news, publishedAt: new \DateTimeImmutable('-3 days'), excerpt: 'Short.');
        $older->setCoverImage($cover);
        $this->createPost('Newer post', $author, $tips);
        $scheduled = $this->createPost('Scheduled', $author, $news, publishedAt: new \DateTimeImmutable('+1 day'));
        $this->createPost('Draft', $author, $news, ContentStatus::Draft);
        $about = $this->createPage('About', ContentStatus::Published);
        $hidden = $this->createPage('Hidden', ContentStatus::Draft);
        foreach ([[$about, 'About us'], [$hidden, 'Hidden']] as $position => [$page, $label]) {
            $this->em()->persist((new MenuItem())->setLocation(MenuLocation::Main)->setLocale('en')->setType(MenuItemType::Page)->setLabel($label)->setPage($page)->setPosition($position));
        }
        $this->em()->persist((new MenuItem())->setLocation(MenuLocation::Footer)->setLocale('en')->setType(MenuItemType::Url)->setLabel('Docs')->setUrl('https://example.com/docs'));
        $this->em()->flush();

        $this->client->request('GET', '/api/v1/posts');
        $posts = $this->response();
        self::assertSame(['Newer post', 'Older post'], array_column($posts['data'], 'title'));
        self::assertSame('News', $posts['data'][1]['category']['name']);
        self::assertSame('Short.', $posts['data'][1]['excerpt']);
        self::assertSame('Author User', $posts['data'][1]['author']['name']);
        self::assertArrayNotHasKey('email', $posts['data'][1]['author']);
        self::assertSame('http://localhost/uploads/media/cover.png', $posts['data'][1]['cover_image']['url']);
        self::assertSame('http://localhost/blog/older-post', $posts['data'][1]['url']);

        $this->client->request('GET', '/api/v1/posts?category=tips');
        self::assertSame(['Newer post'], array_column($this->response()['data'], 'title'));

        $this->client->request('GET', '/api/v1/posts/'.$older->getId());
        self::assertSame('', $this->response()['data']['html']);
        $this->client->request('GET', '/api/v1/posts/'.$scheduled->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/categories');
        self::assertSame(['News', 'Tips'], array_column($this->response()['data'], 'name'));
        self::assertSame('http://localhost/blog/category/news', $this->response()['data'][0]['url']);

        $this->client->request('GET', '/api/v1/menus');
        $menus = $this->response();
        self::assertSame([['label' => 'About us', 'url' => 'http://localhost/about', 'children' => []]], $menus['data']['main'], 'Entries to drafts are left out.');
        self::assertSame([['label' => 'Docs', 'url' => 'https://example.com/docs', 'children' => []]], $menus['data']['footer']);
        self::assertSame('en', $menus['meta']['locale']);

        $this->client->request('GET', '/api/v1/media?per_page=1');
        $media = $this->response();
        self::assertSame(1, $media['meta']['total']);
        self::assertSame('Cover', $media['data'][0]['alt']);
        self::assertSame(1200, $media['data'][0]['width']);
        $this->client->request('GET', '/api/v1/media/'.$cover->getId());
        self::assertSame('image/png', $this->response()['data']['mime_type']);
    }

    public function testApiOnlyAnswersToGet(): void
    {
        $this->enableApi();
        $page = $this->createPage('About', ContentStatus::Published);

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->client->request($method, '/api/v1/pages/'.$page->getId(), ['title' => 'Changed']);
            self::assertResponseStatusCodeSame(405, $method);
            self::assertSame(405, $this->response()['error']['status']);
        }

        self::assertSame('About', $this->entity(Page::class, $page->getId())->getTitle());
    }

    public function testOpenApiDescribesEveryRoute(): void
    {
        $this->enableApi();

        $this->client->request('GET', '/api/v1/openapi.json');
        self::assertResponseIsSuccessful();
        $description = $this->response();

        self::assertSame('3.1.0', $description['openapi']);
        self::assertSame('http://localhost/api/v1', $description['servers'][0]['url']);

        // Every route of the API is documented, and nothing is documented that does not exist.
        $routes = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, 'api_') && 'api_openapi' !== $name) {
                $routes[] = substr($route->getPath(), \strlen('/api/v1')) ?: '/';
            }
        }
        sort($routes);
        $documented = array_keys($description['paths']);
        sort($documented);
        self::assertSame($routes, $documented);

        // Every reference points to a schema that is defined.
        preg_match_all('~#/components/schemas/(\w+)~', (string) $this->client->getResponse()->getContent(), $matches);
        foreach (array_unique($matches[1]) as $schema) {
            self::assertArrayHasKey($schema, $description['components']['schemas']);
        }

        $this->client->request('GET', '/api/v1');
        self::assertSame('http://localhost/api/v1/openapi.json', $this->response()['links']['openapi']);
        self::assertSame(['en'], $this->response()['locales']);
    }

    private function enableApi(): void
    {
        static::getContainer()->get(Settings::class)->set(ApiSubscriber::ENABLED_SETTING, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function response(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 64, \JSON_THROW_ON_ERROR);
    }
}
