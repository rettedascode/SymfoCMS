<?php

namespace App\Tests\Browser;

use App\Entity\Page;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverHasInputDevices;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base class for tests that drive a real browser (headless Chrome) with Symfony Panther.
 *
 * They cover what the functional tests cannot see: dragging, the keyboard
 * and everything else that happens in JavaScript. Run them with
 * "composer test:browser". They need Chrome and a matching driver
 * ("vendor/bin/bdi detect drivers"); without them the tests are skipped.
 */
abstract class BrowserTestCase extends PantherTestCase
{
    protected const PASSWORD = 'correct-horse-battery';

    protected Client $browser;

    protected function setUp(): void
    {
        // The browser talks to a web server of its own, which uses the same test database as this process.
        static::bootKernel();
        $entityManager = $this->em();
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        (new Filesystem())->remove((string) static::getContainer()->getParameter('app.media_dir'));
        static::getContainer()->get('cache.rate_limiter')->clear();

        // A driver downloaded with "vendor/bin/bdi detect drivers" is found through the search path,
        // which gives Panther its full path. The relative "./drivers/..." cannot be started on Windows.
        $drivers = realpath(\dirname(__DIR__, 2).'/drivers');
        $path = (string) (getenv('PATH') ?: getenv('Path'));
        if (false !== $drivers && !str_contains($path, $drivers)) {
            putenv('PATH='.$drivers.\PATH_SEPARATOR.$path);
        }

        try {
            $this->browser = static::createPantherClient(
                // PHP's built-in server needs the router script to hand requests for stylesheets and scripts to Symfony.
                ['browser' => static::CHROME, 'router' => \dirname(__DIR__, 2).'/bin/dev-router.php'],
                [],
                ['capabilities' => ['goog:loggingPrefs' => ['browser' => 'ALL']]],
            );
            // The browser only starts with the first command, so this is where a missing browser shows.
            $this->browser->manage()->window()->setSize(new WebDriverDimension(1440, 1000));
            $this->browser->manage()->deleteAllCookies();
        } catch (\Throwable $exception) {
            self::markTestSkipped('No browser available for browser tests: '.$exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // An editor with unsaved changes asks before the page is left; the next test must not wait for an answer.
        try {
            $this->browser->executeScript('window.onbeforeunload = null; window.addEventListener("beforeunload", (event) => event.stopImmediatePropagation(), true);');
        } catch (\Throwable) {
            // No page was open.
        }

        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(UserRole $role): User
    {
        $user = (new User())
            ->setEmail(strtolower($role->name).'@example.com')
            ->setName(ucfirst(strtolower($role->name)).' User')
            ->setRole($role)
            ->setActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     */
    protected function createPage(string $title, array $blocks = [], ContentStatus $status = ContentStatus::Draft): Page
    {
        $page = (new Page())
            ->setTitle($title)
            ->setSlug(strtolower(str_replace(' ', '-', $title)))
            ->setStatus($status)
            ->setBlocks($blocks);
        $this->em()->persist($page);
        $this->em()->flush();

        return $page;
    }

    /**
     * Logs in through the login form, like a person.
     */
    protected function logIn(UserRole $role = UserRole::Admin): User
    {
        $user = $this->createUser($role);

        $this->browser->request('GET', '/login');
        $this->browser->submitForm('Log in', ['_username' => $user->getEmail(), '_password' => self::PASSWORD]);
        $this->browser->waitFor('.sidebar');

        return $user;
    }

    protected function find(string $css): WebDriverElement
    {
        return $this->browser->findElement(WebDriverBy::cssSelector($css));
    }

    /**
     * @return list<WebDriverElement>
     */
    protected function findAll(string $css): array
    {
        return array_values($this->browser->findElements(WebDriverBy::cssSelector($css)));
    }

    /**
     * Runs JavaScript in the page and returns its result.
     */
    protected function js(string $script, mixed ...$arguments): mixed
    {
        return $this->browser->executeScript($script, $arguments);
    }

    /**
     * Drags one element onto another with the mouse, in small steps like a hand would.
     *
     * @param float $targetY where to let go inside the target, from 0 (top edge) to 1 (bottom edge)
     */
    protected function drag(WebDriverElement $handle, WebDriverElement $target, float $targetY = 0.5): void
    {
        $this->js('arguments[1].scrollIntoView({block: "nearest"}); arguments[0].scrollIntoView({block: "center"})', $handle, $target);
        $from = $this->center($handle);
        $to = $this->center($target, $targetY);
        // The pointer cannot leave the window; near its edge the page would scroll along anyway.
        $height = (int) $this->js('return window.innerHeight');
        $to[1] = max(8.0, min($height - 8.0, $to[1]));
        $steps = 12;

        $actions = $this->actions()
            ->moveToElement($handle)
            ->clickAndHold()
            ->moveByOffset(4, 6)
            ->moveByOffset(4, 6);
        for ($i = 1; $i <= $steps; ++$i) {
            $actions->moveByOffset((int) round(($to[0] - $from[0] - 8) / $steps), (int) round(($to[1] - $from[1] - 12) / $steps));
        }
        $actions->perform();
        // Hold still for a moment before letting go, as a hand does; the list settles on the landing spot.
        usleep(200_000);
        $this->actions()->release()->perform();

        // The editors rebuild their lists a moment after a drop.
        usleep(350_000);
    }

    /**
     * Presses keys while the given element has the focus.
     */
    protected function press(WebDriverElement $element, string ...$keys): void
    {
        $this->js('arguments[0].focus()', $element);
        foreach ($keys as $key) {
            $this->browser->getKeyboard()->pressKey($key);
            usleep(120_000);
        }
        usleep(250_000);
    }

    /**
     * A keyboard shortcut such as Ctrl+Shift+Z: the last key is pressed while the others are held.
     */
    protected function shortcut(string ...$keys): void
    {
        $key = (string) array_pop($keys);
        $keyboard = $this->browser->getKeyboard();
        foreach ($keys as $modifier) {
            $keyboard->pressKey($modifier);
        }
        $keyboard->sendKeys($key);
        foreach (array_reverse($keys) as $modifier) {
            $keyboard->releaseKey($modifier);
        }
        usleep(300_000);
    }

    /**
     * What the drag-and-drop layer last told screen reader users.
     */
    protected function announcement(): string
    {
        usleep(80_000);

        return (string) $this->js('return document.querySelector("[data-dnd-live]")?.textContent ?? ""');
    }

    /**
     * Fails the test when the page logged JavaScript errors.
     */
    protected function assertNoJavaScriptErrors(): void
    {
        $errors = [];
        foreach ($this->browser->getWebDriver()->manage()->getLog('browser') as $entry) {
            // Answers such as 422 for a refused upload are part of what is tested.
            // The live preview is a sandboxed frame without scripts; the browser mentions that, which is intended.
            if ('SEVERE' === ($entry['level'] ?? '') && !str_contains((string) $entry['message'], 'Failed to load resource') && !str_contains((string) $entry['message'], 'sandboxed')) {
                $errors[] = $entry['message'];
            }
        }

        self::assertSame([], $errors, 'The page logged JavaScript errors.');
    }

    /**
     * Mouse actions go to the driver itself; the wrapper of Panther cannot hold a button down at the current position.
     */
    private function actions(): WebDriverActions
    {
        $driver = $this->browser->getWebDriver();
        \assert($driver instanceof WebDriverHasInputDevices);

        return new WebDriverActions($driver);
    }

    /**
     * @return array{float, float} position in the viewport
     */
    private function center(WebDriverElement $element, float $y = 0.5): array
    {
        /** @var array{x: float, y: float, width: float, height: float} $bounds */
        $bounds = $this->js('const b = arguments[0].getBoundingClientRect(); return {x: b.x, y: b.y, width: b.width, height: b.height};', $element);

        return [$bounds['x'] + $bounds['width'] / 2, $bounds['y'] + $bounds['height'] * $y];
    }
}
