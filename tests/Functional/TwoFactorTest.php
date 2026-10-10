<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Security\TwoFactor\Totp;
use App\Security\TwoFactor\TwoFactorManager;
use App\Tests\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class TwoFactorTest extends AppWebTestCase
{
    public function testUserSwitchesItOnAndThenNeedsACodeToLogIn(): void
    {
        $user = $this->createUser(UserRole::Editor);
        $this->createPage('Home', ContentStatus::Published, homepage: true);
        $this->logIn($user);

        // The account page offers the setup.
        $this->client->request('GET', '/admin/account');
        self::assertSelectorTextContains('.badge--draft', 'Off');
        $crawler = $this->client->clickLink('Set up two-step login');
        self::assertResponseIsSuccessful();
        $secret = str_replace(' ', '', $crawler->filter('[data-secret]')->text());
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string) $crawler->filter('img.two-factor__qr')->attr('src'));

        // A wrong code changes nothing, and the key stays the same for another try.
        $crawler = $this->client->submitForm('Switch on two-step login', ['code' => '000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($secret, str_replace(' ', '', $crawler->filter('[data-secret]')->text()));
        self::assertFalse($this->user($user)->isTwoFactorEnabled());

        // The right code switches it on and shows ten backup codes, once.
        $step = $this->totp()->currentStep();
        $crawler = $this->client->submitForm('Switch on two-step login', ['code' => $this->totp()->code($secret, $step)]);
        self::assertResponseIsSuccessful();
        $backupCodes = $crawler->filter('.backup-codes li')->each(static fn (Crawler $node): string => $node->text());
        self::assertCount(10, $backupCodes);
        self::assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $backupCodes[0]);
        self::assertTrue($this->user($user)->isTwoFactorEnabled());
        self::assertNotContains($backupCodes[0], $this->user($user)->getBackupCodes(), 'Backup codes are not stored in readable form.');
        $crawler = $this->client->request('GET', '/admin/account/two-factor');
        self::assertCount(0, $crawler->filter('.backup-codes'));
        self::assertSelectorTextContains('main', '10 unused backup codes');

        // The session that did the setup keeps working.
        $this->client->request('GET', '/admin/posts');
        self::assertResponseIsSuccessful();

        // Next login: the password alone opens nothing.
        $this->logOut();
        $this->logIn($user);
        foreach (['/admin/posts', '/admin/account', '/admin/account/two-factor', '/', '/admin/media/list'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/login/two-factor', null, $path);
        }
        $this->client->request('POST', '/admin/account/two-factor/disable', ['password' => self::PASSWORD]);
        self::assertResponseRedirects('/login/two-factor');
        self::assertTrue($this->user($user)->isTwoFactorEnabled());

        $this->client->request('GET', '/login/two-factor');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Enter your code');

        $this->client->submitForm('Continue', ['code' => '123456']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash--error', 'not correct');

        // The code used during the setup cannot be used a second time.
        $this->client->submitForm('Continue', ['code' => $this->totp()->code($secret, $step)]);
        self::assertResponseStatusCodeSame(422);

        $this->client->submitForm('Continue', ['code' => $this->totp()->code($secret, $step + 1)]);
        self::assertResponseRedirects('/admin');
        $this->client->request('GET', '/admin/posts');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/login/two-factor');
        self::assertResponseRedirects('/admin');

        // Without the phone, a backup code works - exactly once.
        $this->logOut();
        $this->logIn($user);
        $this->client->request('GET', '/login/two-factor');
        $this->client->submitForm('Continue', ['code' => strtoupper(str_replace('-', ' ', $backupCodes[3]))]);
        self::assertResponseRedirects('/admin');
        self::assertCount(9, $this->user($user)->getBackupCodes());

        $this->logOut();
        $this->logIn($user);
        $this->client->request('GET', '/login/two-factor');
        $this->client->submitForm('Continue', ['code' => $backupCodes[3]]);
        self::assertResponseStatusCodeSame(422);

        // Giving up and logging out is always possible.
        $this->client->submitForm('Log in as somebody else');
        self::assertResponseRedirects();
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testGuessingCodesIsSlowedDown(): void
    {
        $user = $this->userWithTwoFactor(UserRole::Author);
        $this->logIn($user);
        $this->client->request('GET', '/login/two-factor');

        for ($i = 0; $i < 5; ++$i) {
            $this->client->submitForm('Continue', ['code' => '00000'.$i]);
            self::assertSelectorTextContains('.flash--error', 'not correct');
        }

        // From now on even the right code is refused for a while.
        $this->client->submitForm('Continue', ['code' => $this->currentCode($user)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash--error', 'Too many attempts');
        $this->client->request('GET', '/admin/posts');
        self::assertResponseRedirects('/login/two-factor');
    }

    public function testSwitchingOffAndNewBackupCodesNeedThePassword(): void
    {
        $user = $this->userWithTwoFactor(UserRole::Author);
        $oldCodes = $user->getBackupCodes();
        $this->logIn($user);
        $this->client->request('GET', '/login/two-factor');
        $this->client->submitForm('Continue', ['code' => $this->currentCode($user)]);

        $this->client->request('GET', '/admin/account/two-factor');
        $this->client->submitForm('Create new backup codes', ['password' => 'wrong']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($oldCodes, $this->user($user)->getBackupCodes());

        $crawler = $this->client->submitForm('Create new backup codes', ['password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertCount(10, $crawler->filter('.backup-codes li'));
        self::assertNotSame($oldCodes, $this->user($user)->getBackupCodes());

        $this->client->request('GET', '/admin/account/two-factor');
        $this->client->submitForm('Switch off', ['password' => 'wrong']);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->user($user)->isTwoFactorEnabled());

        $this->client->submitForm('Switch off', ['password' => self::PASSWORD]);
        self::assertResponseRedirects('/admin/account');
        self::assertFalse($this->user($user)->isTwoFactorEnabled());
        self::assertSame([], $this->user($user)->getBackupCodes());

        // The requests need the form token.
        $this->client->request('POST', '/admin/account/two-factor/enable', ['code' => '123456']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSessionsOpenedBeforeTheSetupAreAskedForTheCodeToo(): void
    {
        // A browser that is logged in without having passed the second step, e.g. from before it was switched on.
        $user = $this->userWithTwoFactor(UserRole::Editor);
        $this->client->loginUser($user);
        $this->client->request('GET', '/admin');
        $this->client->request('GET', '/admin/pages');

        self::assertResponseRedirects('/login/two-factor');
    }

    public function testAdministratorsCanBeRequiredToUseIt(): void
    {
        $admin = $this->loginAs(UserRole::Admin);

        // Until it is required, nothing changes for anybody.
        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['settings[twoFactorAdmins]' => true]);
        self::assertResponseRedirects('/admin/settings');
        self::assertTrue(static::getContainer()->get(TwoFactorManager::class)->isRequiredForAdmins());

        // The administrator is now held on the setup page ...
        foreach (['/admin/settings', '/admin/pages', '/admin/users', '/'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/admin/account/two-factor', null, $path);
        }
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'have to use two-step login');
        $secret = str_replace(' ', '', $crawler->filter('[data-secret]')->text());

        // ... until it is set up.
        $this->client->submitForm('Switch on two-step login', ['code' => $this->totp()->code($secret, $this->totp()->currentStep())]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->user($admin)->isTwoFactorEnabled());

        // Editors and authors are not affected.
        $this->client->loginUser($this->createUser(UserRole::Editor));
        $this->client->request('GET', '/admin/pages');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/settings');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminResetsTwoStepLoginOfSomebodyWhoLostTheirPhone(): void
    {
        $author = $this->userWithTwoFactor(UserRole::Author);

        // Editors cannot do that.
        $this->loginAs(UserRole::Editor);
        $token = $this->csrfToken('/admin/account/two-factor');
        $this->client->request('POST', '/admin/users/'.$author->getId().'/reset-two-factor', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->user($author)->isTwoFactorEnabled());

        $this->client->loginUser($this->createUser(UserRole::Admin));
        $crawler = $this->client->request('GET', '/admin/users');
        self::assertSelectorTextContains('tbody', '2FA');
        $this->client->submit($crawler->filter('form[action="/admin/users/'.$author->getId().'/reset-two-factor"]')->form());
        self::assertResponseRedirects('/admin/users');
        self::assertFalse($this->user($author)->isTwoFactorEnabled());

        // The author gets in with the password alone again.
        $this->logOut();
        $this->logIn($author);
        $this->client->request('GET', '/admin/posts');
        self::assertResponseIsSuccessful();
    }

    private function userWithTwoFactor(UserRole $role): User
    {
        $user = $this->createUser($role);
        $user->setTotpSecret($this->totp()->generateSecret());
        static::getContainer()->get(TwoFactorManager::class)->regenerateBackupCodes($user);

        return $user;
    }

    private function currentCode(User $user): string
    {
        return $this->totp()->code((string) $this->user($user)->getTotpSecret(), $this->totp()->currentStep());
    }

    private function totp(): Totp
    {
        return static::getContainer()->get(Totp::class);
    }

    private function user(User $user): User
    {
        $this->em()->clear();

        return $this->entity(User::class, $user->getId());
    }

    /**
     * Logs in through the real login form, as a person would.
     */
    private function logIn(User $user): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['_username' => $user->getEmail(), '_password' => self::PASSWORD]);
        self::assertResponseRedirects();
    }

    private function logOut(): void
    {
        $this->client->getCookieJar()->clear();
    }
}
