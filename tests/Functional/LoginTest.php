<?php

namespace App\Tests\Functional;

use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class LoginTest extends AppWebTestCase
{
    public function testAdminAreaRedirectsAnonymousVisitorsToLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->createUser(UserRole::Admin, 'admin@example.com');

        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Log in', [
            '_username' => 'admin@example.com',
            '_password' => self::PASSWORD,
        ]);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar');
    }

    public function testLoginWithWrongPasswordShowsAnError(): void
    {
        $this->createUser(UserRole::Admin, 'admin@example.com');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', [
            '_username' => 'admin@example.com',
            '_password' => 'not-the-password',
        ]);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Invalid credentials');
    }

    public function testDeactivatedUserCannotLogIn(): void
    {
        $this->createUser(UserRole::Editor, 'former@example.com', active: false);

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', [
            '_username' => 'former@example.com',
            '_password' => self::PASSWORD,
        ]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'deactivated');
    }

    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        $this->createUser(UserRole::Admin, 'throttle@example.com');

        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $this->client->request('GET', '/login');
            $this->client->submitForm('Log in', [
                '_username' => 'throttle@example.com',
                '_password' => 'wrong-password',
            ]);
            $this->client->followRedirect();
        }

        self::assertSelectorTextContains('.flash--error', 'Too many failed login attempts');

        // Even the correct password is refused while the limit is active.
        $this->client->submitForm('Log in', [
            '_username' => 'throttle@example.com',
            '_password' => self::PASSWORD,
        ]);
        self::assertResponseRedirects('/login');
    }

    public function testLoginFormIsShownInGermanForGermanBrowsers(): void
    {
        $this->client->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        self::assertSelectorTextContains('button[type="submit"]', 'Anmelden');
    }
}
