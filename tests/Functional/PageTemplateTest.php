<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Entity\PageTemplate;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class PageTemplateTest extends AppWebTestCase
{
    public function testNewPageStartsFromABuiltInTemplate(): void
    {
        $this->loginAs(UserRole::Editor);

        $crawler = $this->client->request('GET', '/admin/pages/new');
        self::assertSame(['Blank page', 'Landing page', 'About', 'Contact'], $crawler->filter('.template-choice__text strong')->each(static fn ($node) => $node->text()));
        self::assertSame('blank', $crawler->filter('.template-choice input[checked]')->attr('value'), 'A blank page stays the default.');

        $this->client->submitForm('Continue', ['content[title]' => 'Our Product', 'content[template]' => 'landing']);

        $page = $this->em()->getRepository(Page::class)->findOneBy(['slug' => 'our-product']);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame(['text', 'button', 'heading', 'columns', 'quote'], array_column($page->getBlocks(), 'type'));
        self::assertSame(ContentStatus::Draft, $page->getStatus());

        // Blank is still blank.
        $this->client->request('GET', '/admin/pages/new');
        $this->client->submitForm('Continue', ['content[title]' => 'Empty']);
        $empty = $this->em()->getRepository(Page::class)->findOneBy(['slug' => 'empty']);
        self::assertInstanceOf(Page::class, $empty);
        self::assertSame([], $empty->getBlocks());
    }

    public function testTemplateTextsFollowTheLanguageOfThePage(): void
    {
        $this->enableLocales('en', 'de');
        $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/pages/new?locale=de');
        $this->client->submitForm('Continue', ['content[title]' => 'Kontakt', 'content[template]' => 'contact']);

        $page = $this->em()->getRepository(Page::class)->findOneBy(['locale' => 'de']);
        self::assertInstanceOf(Page::class, $page);
        self::assertStringContainsString('Wir freuen uns', $page->getBlocks()[0]['data']['content']);
    }

    public function testAdminSavesPageAsTemplateAndItIsOffered(): void
    {
        $this->loginAs(UserRole::Admin);
        $page = $this->createPage('Event', ContentStatus::Published);
        $page->setBlocks([['type' => 'heading', 'data' => ['text' => 'When and where', 'level' => '2']]]);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $button = $crawler->selectButton('Save as template');
        $this->client->request('POST', (string) $button->attr('formaction'), ['_token' => $button->attr('value'), 'template_name' => 'Event announcement']);
        self::assertResponseRedirects('/admin/pages/'.$page->getId().'/edit');

        $template = $this->em()->getRepository(PageTemplate::class)->findOneBy([]);
        self::assertInstanceOf(PageTemplate::class, $template);
        self::assertSame('Event announcement', $template->getName());

        // Everybody who creates pages can use it.
        $this->loginAs(UserRole::Editor);
        $this->client->request('GET', '/admin/pages/new');
        self::assertSelectorTextContains('.template-choice', 'Event announcement');
        $this->client->submitForm('Continue', ['content[title]' => 'Summer Party', 'content[template]' => 'saved-'.$template->getId()]);
        $created = $this->em()->getRepository(Page::class)->findOneBy(['slug' => 'summer-party']);
        self::assertInstanceOf(Page::class, $created);
        self::assertSame('When and where', $created->getBlocks()[0]['data']['text']);
    }

    public function testOnlyAdminsSaveAndDeleteTemplates(): void
    {
        $template = new PageTemplate('Kept', []);
        $this->em()->persist($template);
        $this->em()->flush();
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Page', ContentStatus::Published);

        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        self::assertCount(0, $crawler->selectButton('Save as template'));
        $token = $this->csrfToken('/admin/pages');

        $this->client->request('POST', '/admin/pages/'.$page->getId().'/save-as-template', ['_token' => $token, 'template_name' => 'X']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/page-templates');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/page-templates/'.$template->getId().'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);

        // An admin can, with a valid token.
        $this->loginAs(UserRole::Admin);
        $this->client->request('POST', '/admin/page-templates/'.$template->getId().'/delete', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $token = $this->csrfToken('/admin/page-templates');
        $this->client->request('POST', '/admin/page-templates/'.$template->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/page-templates');
        self::assertSame(0, $this->em()->getRepository(PageTemplate::class)->count([]));
    }
}
