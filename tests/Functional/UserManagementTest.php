<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class UserManagementTest extends AppWebTestCase
{
    public function testOnlyAdminsCanManageUsers(): void
    {
        $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/users/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatesUser(): void
    {
        $this->loginAs(UserRole::Admin);

        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Save', [
            'user[name]' => 'Erin Editor',
            'user[email]' => 'Erin@Example.com',
            'user[plainPassword]' => 'a-long-password',
            'user[role]' => UserRole::Editor->value,
            'user[locale]' => 'de',
            'user[active]' => '1',
        ]);

        self::assertResponseRedirects('/admin/users');

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'erin@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserRole::Editor, $user->getRole());
        self::assertSame('de', $user->getLocale());
        self::assertNotSame('a-long-password', $user->getPassword(), 'Passwords must be stored hashed.');
    }

    public function testShortPasswordIsRejected(): void
    {
        $this->loginAs(UserRole::Admin);

        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Save', [
            'user[name]' => 'Sam Short',
            'user[email]' => 'sam@example.com',
            'user[plainPassword]' => 'short',
            'user[role]' => UserRole::Author->value,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__errors', 'at least 8 characters');
    }

    public function testAdminDeactivatesAndDeletesAnotherUser(): void
    {
        $this->loginAs(UserRole::Admin);
        $author = $this->createUser(UserRole::Author);
        $authorId = $author->getId();

        $token = $this->csrfToken('/admin/users');

        $this->client->request('POST', '/admin/users/'.$authorId.'/toggle', ['_token' => $token]);
        self::assertResponseRedirects('/admin/users');
        $this->em()->clear();
        self::assertFalse($this->em()->find(User::class, $authorId)?->isActive());

        $this->client->request('POST', '/admin/users/'.$authorId.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/users');
        $this->em()->clear();
        self::assertNull($this->em()->find(User::class, $authorId));
    }

    public function testAdminCannotDeleteOrDeactivateThemselves(): void
    {
        $admin = $this->loginAs(UserRole::Admin);
        $this->createUser(UserRole::Author);
        $token = $this->csrfToken('/admin/users');

        $this->client->request('POST', '/admin/users/'.$admin->getId().'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/users/'.$admin->getId().'/toggle', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $this->loginAs(UserRole::Admin);
        $author = $this->createUser(UserRole::Author);

        $this->client->request('POST', '/admin/users/'.$author->getId().'/delete', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminInterfaceUsesTheLanguageOfTheUser(): void
    {
        $user = $this->createUser(UserRole::Admin);
        $user->setLocale('de');
        $this->em()->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/admin/users');

        self::assertSelectorTextContains('h1', 'Benutzer');
    }
}
