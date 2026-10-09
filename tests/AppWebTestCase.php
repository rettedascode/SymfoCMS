<?php

namespace App\Tests;

use App\Entity\Category;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Service\ContentLocales;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base class for functional tests: every test starts with an empty database.
 */
abstract class AppWebTestCase extends WebTestCase
{
    protected const PASSWORD = 'correct-horse-battery';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $entityManager = $this->em();
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // Uploads of earlier tests are removed together with their database rows.
        (new Filesystem())->remove($this->mediaDir());

        // Login throttling counters must not leak from one test (or test run) into the next.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Loads an entity that must exist, e.g. to check what a request changed.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function entity(string $class, ?int $id): object
    {
        $entity = $this->em()->find($class, $id);
        self::assertInstanceOf($class, $entity);

        return $entity;
    }

    protected function json(mixed $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR);
    }

    protected function createUser(UserRole $role, ?string $email = null, bool $active = true): User
    {
        $user = (new User())
            ->setEmail($email ?? strtolower($role->name).'@example.com')
            ->setName(ucfirst(strtolower($role->name)).' User')
            ->setRole($role)
            ->setActive($active);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));

        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /**
     * Makes the test site multilingual. The first language is the default one.
     */
    protected function enableLocales(string ...$locales): void
    {
        static::getContainer()->get(ContentLocales::class)->save(array_values($locales));
    }

    protected function createPage(string $title, ContentStatus $status, bool $homepage = false, string $locale = 'en', ?Page $translationOf = null): Page
    {
        $page = (new Page())
            ->setLocale($locale)
            ->setTitle($title)
            ->setSlug(strtolower(str_replace(' ', '-', $title)))
            ->setStatus($status)
            ->setHomepage($homepage);
        if (null !== $translationOf) {
            $page->setTranslationGroup($translationOf->getTranslationGroup());
        }

        $this->em()->persist($page);
        $this->em()->flush();

        return $page;
    }

    protected function createCategory(string $name, string $locale = 'en'): Category
    {
        $category = (new Category())->setLocale($locale)->setName($name)->setSlug(strtolower(str_replace(' ', '-', $name)));

        $this->em()->persist($category);
        $this->em()->flush();

        return $category;
    }

    /**
     * Creates a post that is published since yesterday unless told otherwise.
     */
    protected function createPost(
        string $title,
        ?User $author = null,
        ?Category $category = null,
        ContentStatus $status = ContentStatus::Published,
        ?\DateTimeImmutable $publishedAt = new \DateTimeImmutable('-1 day'),
        ?string $excerpt = null,
        string $locale = 'en',
        ?Post $translationOf = null,
    ): Post {
        $post = (new Post())
            ->setLocale($locale)
            ->setTranslationGroup($translationOf?->getTranslationGroup() ?? bin2hex(random_bytes(16)))
            ->setTitle($title)
            ->setSlug(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-'))
            ->setStatus($status)
            ->setPublishedAt($publishedAt)
            ->setAuthor($author)
            ->setCategory($category)
            ->setExcerpt($excerpt);

        $this->em()->persist($post);
        $this->em()->flush();

        return $post;
    }

    protected function loginAs(UserRole $role): User
    {
        $user = $this->createUser($role);
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * Reads the session-based CSRF token used by hand-written admin forms and fetch() requests.
     */
    protected function csrfToken(string $url, string $selector = 'input[name="_token"]', string $attribute = 'value'): string
    {
        $crawler = $this->client->request('GET', $url);
        $node = $crawler->filter($selector);
        self::assertGreaterThan(0, $node->count(), \sprintf('No CSRF token found on %s using "%s".', $url, $selector));

        return (string) $node->first()->attr($attribute);
    }

    protected function mediaDir(): string
    {
        return (string) static::getContainer()->getParameter('app.media_dir');
    }
}
