<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class BlogTest extends AppWebTestCase
{
    public function testAuthorWritesAndPublishesOwnPost(): void
    {
        $author = $this->loginAs(UserRole::Author);

        $this->client->request('GET', '/admin/posts/new');
        $this->client->submitForm('Continue', ['content[title]' => 'My First Post']);

        $post = $this->em()->getRepository(Post::class)->findOneBy(['slug' => 'my-first-post']);
        self::assertInstanceOf(Post::class, $post);
        self::assertSame($author->getId(), $post->getAuthor()?->getId());
        self::assertResponseRedirects('/admin/posts/'.$post->getId().'/edit');

        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('#post_author'), 'Authors cannot hand their posts to somebody else.');

        $this->client->submitForm('Publish', [
            'post[excerpt]' => 'A short summary.',
            'post[blocks]' => json_encode([['type' => 'text', 'data' => ['content' => '<p>Hello readers</p>']]]),
        ]);
        self::assertResponseRedirects();

        $this->em()->clear();
        $post = $this->em()->find(Post::class, $post->getId());
        self::assertSame(ContentStatus::Published, $post->getStatus());
        self::assertNotNull($post->getPublishedAt(), 'Publishing without a date uses the current time.');

        $this->client->restart();
        $this->client->request('GET', '/blog/my-first-post');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'My First Post');
        self::assertSelectorTextContains('.post-meta', 'by Author User');
        self::assertSelectorTextContains('.block-text', 'Hello readers');
        self::assertSelectorExists('meta[name="description"][content="A short summary."]');
        self::assertSelectorExists('meta[property="og:type"][content="article"]');
    }

    public function testAuthorsOnlySeeAndEditTheirOwnPosts(): void
    {
        $editor = $this->createUser(UserRole::Editor);
        $foreign = $this->createPost('Editors Post', author: $editor);
        $author = $this->loginAs(UserRole::Author);
        $own = $this->createPost('Authors Post', author: $author);

        $crawler = $this->client->request('GET', '/admin/posts');
        self::assertResponseIsSuccessful();
        self::assertSame(['Authors Post'], $crawler->filter('tbody th a')->each(static fn ($a) => $a->text()));

        $this->client->request('GET', '/admin/posts/'.$own->getId().'/edit');
        self::assertResponseIsSuccessful();

        $token = $this->csrfToken('/admin/posts');
        $foreignId = $foreign->getId();
        $this->client->request('GET', '/admin/posts/'.$foreignId.'/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/posts/'.$foreignId.'/edit', ['post' => ['title' => 'Hijacked']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/posts/'.$foreignId.'/autosave', server: ['HTTP_X_CSRF_TOKEN' => $token], content: '{}');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/posts/'.$foreignId.'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertSame('Editors Post', $this->em()->find(Post::class, $foreignId)->getTitle());
    }

    public function testEditorManagesAllPostsAndCanReassignTheAuthor(): void
    {
        $author = $this->createUser(UserRole::Author);
        $post = $this->createPost('Authors Post', author: $author);
        $editor = $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/posts/'.$post->getId().'/edit');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Save changes', ['post[author]' => (string) $editor->getId(), 'post[blocks]' => '[]']);

        $this->em()->clear();
        self::assertSame($editor->getId(), $this->em()->find(Post::class, $post->getId())->getAuthor()?->getId());
    }

    public function testFutureDateSchedulesThePost(): void
    {
        $this->loginAs(UserRole::Editor);
        $post = $this->createPost('Coming Soon', publishedAt: null, status: ContentStatus::Draft);

        $this->client->request('GET', '/admin/posts/'.$post->getId().'/edit');
        $this->client->submitForm('Publish', [
            'post[publishedAt]' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i'),
            'post[blocks]' => '[]',
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'published automatically');

        // Logged-in users can preview it, the list marks it as scheduled.
        $this->client->request('GET', '/blog/coming-soon');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.edit-bar__draft');
        $this->client->request('GET', '/admin/posts');
        self::assertSelectorTextSame('.badge--scheduled', 'Scheduled');

        // Visitors do not see it anywhere yet.
        $this->client->restart();
        $this->client->request('GET', '/blog/coming-soon');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/blog');
        self::assertSelectorNotExists('.post-card');
        $this->client->request('GET', '/feed.xml');
        self::assertStringNotContainsString('Coming Soon', (string) $this->client->getResponse()->getContent());

        // Once the date has passed it appears on its own.
        $post = $this->em()->find(Post::class, $post->getId());
        $post->setPublishedAt(new \DateTimeImmutable('-1 minute'));
        $this->em()->flush();
        $this->client->request('GET', '/blog/coming-soon');
        self::assertResponseIsSuccessful();
    }

    public function testBlogIndexIsPaginated(): void
    {
        for ($i = 1; $i <= 8; ++$i) {
            $this->createPost('Post Number '.$i, publishedAt: new \DateTimeImmutable('-'.$i.' days'));
        }
        $this->createPost('Hidden Draft', status: ContentStatus::Draft, publishedAt: null);

        $crawler = $this->client->request('GET', '/blog');
        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('.post-card'));
        self::assertSelectorTextSame('.post-card__title', 'Post Number 1');
        self::assertSelectorTextContains('.pagination__status', 'Page 1 of 2');
        self::assertSelectorExists('.pagination a[rel="next"][href="/blog/page/2"]');
        self::assertSelectorExists('link[rel="next"]');

        $crawler = $this->client->request('GET', '/blog/page/2');
        self::assertCount(2, $crawler->filter('.post-card'));
        self::assertSelectorExists('.pagination a[rel="prev"][href="/blog"]');

        $this->client->request('GET', '/blog/page/3');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCategoryPagesListOnlyTheirPosts(): void
    {
        $news = $this->createCategory('News');
        $this->createCategory('Empty');
        $this->createPost('Big Announcement', category: $news);
        $this->createPost('Random Thoughts');

        $crawler = $this->client->request('GET', '/blog/category/news');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'News');
        self::assertSame(['Big Announcement'], $crawler->filter('.post-card__title')->each(static fn ($node) => $node->text()));
        self::assertSame(['All posts', 'News'], $crawler->filter('.category-nav a')->each(static fn ($a) => $a->text()), 'Categories without public posts are not linked.');

        $this->client->request('GET', '/blog/category/does-not-exist');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRssFeedListsPublishedPosts(): void
    {
        $author = $this->createUser(UserRole::Author);
        $this->createPost('Feed & Entry', author: $author, category: $this->createCategory('News'), excerpt: 'Summary <with> markup');
        $this->createPost('Draft Entry', status: ContentStatus::Draft, publishedAt: null);

        $this->client->request('GET', '/feed.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/rss+xml; charset=UTF-8');
        $xml = simplexml_load_string((string) $this->client->getResponse()->getContent());
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, 'The feed must be well-formed XML.');
        self::assertSame('Test Site', (string) $xml->channel->title);
        self::assertCount(1, $xml->channel->item);
        self::assertSame('Feed & Entry', (string) $xml->channel->item[0]->title);
        self::assertSame('http://localhost/blog/feed-entry', (string) $xml->channel->item[0]->link);
        self::assertSame('Summary <with> markup', (string) $xml->channel->item[0]->description);
        self::assertSame('News', (string) $xml->channel->item[0]->category);
    }

    public function testEditorManagesCategories(): void
    {
        $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/categories');
        $this->client->submitForm('Add category', ['category[name]' => 'Behind the Scenes']);
        self::assertResponseRedirects('/admin/categories');

        $category = $this->em()->getRepository(Category::class)->findOneBy(['slug' => 'behind-the-scenes']);
        self::assertInstanceOf(Category::class, $category);

        $post = $this->createPost('Categorised', category: $category);
        $token = $this->csrfToken('/admin/categories');
        $this->client->request('POST', '/admin/categories/'.$category->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/categories');

        $this->em()->clear();
        $post = $this->em()->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $post, 'Deleting a category keeps its posts.');
        self::assertNull($post->getCategory());
    }

    public function testAuthorsCannotManageCategories(): void
    {
        $this->loginAs(UserRole::Author);

        $this->client->request('GET', '/admin/categories');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletingAUserKeepsTheirPosts(): void
    {
        $author = $this->createUser(UserRole::Author);
        $post = $this->createPost('Orphan', author: $author);
        $this->loginAs(UserRole::Admin);

        $token = $this->csrfToken('/admin/users');
        $this->client->request('POST', '/admin/users/'.$author->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/users');

        $this->em()->clear();
        $post = $this->em()->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $post);
        self::assertNull($post->getAuthor());
        self::assertNull($this->em()->find(User::class, $author->getId()));
    }
}
