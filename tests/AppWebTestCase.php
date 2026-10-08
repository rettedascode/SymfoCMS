<?php

namespace App\Tests;

use App\Entity\Page;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

        // Login throttling counters must not leak from one test (or test run) into the next.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
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

    protected function createPage(string $title, ContentStatus $status, bool $homepage = false): Page
    {
        $page = (new Page())
            ->setTitle($title)
            ->setSlug(strtolower(str_replace(' ', '-', $title)))
            ->setStatus($status)
            ->setHomepage($homepage);

        $this->em()->persist($page);
        $this->em()->flush();

        return $page;
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
    protected function csrfToken(string $url, string $selector = 'input[name="_token"]'): string
    {
        $crawler = $this->client->request('GET', $url);
        $node = $crawler->filter($selector);
        self::assertGreaterThan(0, $node->count(), \sprintf('No CSRF token found on %s using "%s".', $url, $selector));

        return (string) ($node->first()->attr('value') ?? $node->first()->attr('content'));
    }
}
