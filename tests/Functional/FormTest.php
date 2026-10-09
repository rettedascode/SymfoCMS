<?php

namespace App\Tests\Functional;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Service\SpamGuard;
use App\Tests\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class FormTest extends AppWebTestCase
{
    public function testEditorBuildsAForm(): void
    {
        $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/forms/new');
        self::assertInputValueSame('form[submitLabel]', 'Send');

        $this->client->submitForm('Save', [
            'form[name]' => 'Contact form',
            'form[notifyEmail]' => 'owner@example.com',
            'form[fields]' => $this->json([
                ['type' => 'text', 'label' => ' Your name ', 'required' => true],
                ['type' => 'select', 'label' => 'Topic', 'options' => ['Question', '', 'Question', 'Praise']],
                ['type' => 'select', 'label' => 'No choices', 'options' => []],
                ['type' => 'consent', 'label' => 'I agree', 'required' => false],
                ['type' => 'file', 'label' => 'Upload'],
                ['type' => 'text', 'label' => ''],
            ]),
        ]);

        $form = $this->em()->getRepository(Form::class)->findOneBy([]);
        self::assertInstanceOf(Form::class, $form);
        self::assertResponseRedirects('/admin/forms/'.$form->getId().'/edit');

        $fields = $form->getFields();
        self::assertSame(['text', 'select', 'consent'], array_column($fields, 'type'), 'Unknown types, empty labels and selections without choices are dropped.');
        self::assertSame('Your name', $fields[0]['label']);
        self::assertSame(['Question', 'Praise'], $fields[1]['options']);
        self::assertTrue($fields[2]['required'], 'Consent is always required.');
        self::assertMatchesRegularExpression('/^f[a-z0-9]{8}$/', $fields[0]['id']);

        // Saving again keeps the ids, so stored answers stay attached to their questions.
        $this->client->followRedirect();
        $this->client->submitForm('Save', ['form[fields]' => $this->json($fields)]);
        $this->em()->clear();
        self::assertSame(array_column($fields, 'id'), array_column($this->entity(Form::class, $form->getId())->getFields(), 'id'));
    }

    public function testFormWithoutFieldsIsRejected(): void
    {
        $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/forms/new');
        $this->client->submitForm('Save', ['form[name]' => 'Empty', 'form[fields]' => '[]']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__errors', 'at least one field');
    }

    public function testVisitorSendsFormAndItArrivesInTheInbox(): void
    {
        $form = $this->createContactForm('owner@example.com');
        $this->createFormPage($form);

        $crawler = $this->client->request('GET', '/contact');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.site-form'));
        self::assertSelectorExists('.site-form input[type="email"][required][autocomplete="email"]');
        self::assertSelectorExists('.site-form__trap input[name="website"][tabindex="-1"]');
        self::assertFalse($this->client->getRequest()->hasSession() && $this->client->getRequest()->getSession()->isStarted(), 'Showing a form does not start a session.');

        $this->submit($crawler, ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'message' => "Hello,\nI have a question.", 'topic' => 'Question', 'consent' => '1']);
        self::assertResponseRedirects('/contact#form-'.$form->getId().'-message', 303);

        // The owner is notified and can simply reply.
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'To', 'owner@example.com');
        self::assertEmailHeaderSame($email, 'Reply-To', 'ada@example.com');
        self::assertEmailTextBodyContains($email, 'I have a question.');

        // The visitor sees the confirmation instead of the form - once.
        $this->client->followRedirect();
        self::assertSelectorTextSame('.form-message--success', 'Thanks, we will be in touch!');
        self::assertSelectorNotExists('form.site-form');
        $this->client->request('GET', '/contact');
        self::assertSelectorExists('form.site-form');

        // Stored with the labels of the questions.
        $submission = $this->em()->getRepository(FormSubmission::class)->findOneBy([]);
        self::assertInstanceOf(FormSubmission::class, $submission);
        self::assertFalse($submission->isRead());
        self::assertSame(
            ['Your name' => 'Ada Lovelace', 'Email' => 'ada@example.com', 'Message' => "Hello,\nI have a question.", 'Topic' => 'Question', 'Newsletter' => '–', 'I agree' => '✓'],
            array_column($submission->getAnswers(), 'value', 'label'),
        );
    }

    public function testInvalidInputIsReportedAndKept(): void
    {
        $form = $this->createContactForm();
        $this->createFormPage($form);

        $crawler = $this->client->request('GET', '/contact');
        $this->submit($crawler, ['name' => 'Ada', 'email' => 'not-an-email', 'message' => '', 'topic' => 'Hacked']);
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.form-message--error', 'check the marked fields');
        $errors = $crawler->filter('.site-form__error')->each(static fn (Crawler $node) => $node->text());
        self::assertSame(['Please enter a valid email address.', 'Please fill in this field.', 'Please choose one of the options.', 'Please tick this box to continue.'], $errors);
        self::assertSame('Ada', $crawler->filter('.site-form input[type="text"]')->first()->attr('value'), 'What was typed is kept.');
        self::assertSelectorExists('.site-form input[aria-invalid="true"][aria-describedby]');
        self::assertSame(0, $this->em()->getRepository(FormSubmission::class)->count([]));
    }

    public function testSpamIsStoppedWithoutBotheringPeople(): void
    {
        $form = $this->createContactForm('owner@example.com');
        $this->createFormPage($form);
        $valid = ['name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'Buy now', 'consent' => '1'];

        // A filled honeypot: the bot is told everything went fine, but nothing is stored or sent.
        $crawler = $this->client->request('GET', '/contact');
        $this->submit($crawler, $valid, honeypot: 'https://spam.example');
        $this->client->followRedirect();
        self::assertSelectorExists('.form-message--success');
        self::assertSame(0, $this->em()->getRepository(FormSubmission::class)->count([]));
        self::assertEmailCount(0);

        // Sent the instant the page was loaded: asked to try again.
        $crawler = $this->client->request('GET', '/contact');
        $this->submit($crawler, $valid, waited: 0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-message--error', 'very quick');

        // A made-up timestamp does not help.
        $crawler = $this->client->request('GET', '/contact');
        $this->submit($crawler, $valid, started: (time() - 60).'.forged');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-message--error', 'very quick');
        self::assertSame(0, $this->em()->getRepository(FormSubmission::class)->count([]));
    }

    public function testRepeatedSubmissionsAreRateLimited(): void
    {
        $form = $this->createContactForm();
        $this->createFormPage($form);
        $valid = ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hi', 'consent' => '1'];

        for ($i = 1; $i <= 6; ++$i) {
            $crawler = $this->client->request('GET', '/contact');
            $this->submit($crawler, $valid);
            $this->client->followRedirect();
        }

        self::assertSelectorTextContains('.form-message--error', 'several times');
        self::assertSame(5, $this->em()->getRepository(FormSubmission::class)->count([]));
    }

    public function testInboxReadStateExportAndDeletion(): void
    {
        $form = $this->createContactForm();
        $this->em()->persist(new FormSubmission($form, [['label' => 'Your name', 'value' => 'Ada'], ['label' => 'Message', 'value' => '=HYPERLINK("http://evil.example")']]));
        $this->em()->persist(new FormSubmission($form, [['label' => 'Your name', 'value' => 'Grace'], ['label' => 'Phone', 'value' => '+49 123']]));
        $this->em()->flush();
        $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/forms');
        self::assertSelectorTextContains('tbody .badge', '2 new');

        $crawler = $this->client->request('GET', '/admin/forms/'.$form->getId().'/submissions');
        self::assertCount(2, $crawler->filter('tbody tr.is-unread'));

        // Opening a message marks it as read.
        $this->client->click($crawler->filter('tbody th a')->first()->link());
        self::assertSelectorTextContains('.answers', 'Grace');
        $crawler = $this->client->request('GET', '/admin/forms/'.$form->getId().'/submissions');
        self::assertCount(1, $crawler->filter('tbody tr.is-unread'));

        // CSV export: one column per question, formulas defused.
        $this->client->request('GET', '/admin/forms/'.$form->getId().'/export.csv');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $this->client->getInternalResponse()->getContent();
        $lines = explode("\n", trim(substr($csv, 3)));
        self::assertSame('"Sent on";"Your name";Phone;Message', $lines[0]);
        self::assertStringContainsString("Grace;\"'+49 123\";", $csv);
        self::assertStringContainsString('"\'=HYPERLINK(""http://evil.example"")"', $csv);

        // Delete one message.
        $submission = $this->em()->getRepository(FormSubmission::class)->findOneBy([]);
        self::assertInstanceOf(FormSubmission::class, $submission);
        $token = $this->csrfToken('/admin/forms/'.$form->getId().'/submissions');
        $this->client->request('POST', '/admin/forms/submissions/'.$submission->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/forms/'.$form->getId().'/submissions');
        self::assertSame(1, $this->em()->getRepository(FormSubmission::class)->count([]));
    }

    public function testOldSubmissionsAreDeletedAfterTheRetentionPeriod(): void
    {
        $form = $this->createContactForm();
        $old = new FormSubmission($form, [['label' => 'Your name', 'value' => 'Old']]);
        $recent = new FormSubmission($form, [['label' => 'Your name', 'value' => 'Recent']]);
        $this->em()->persist($old);
        $this->em()->persist($recent);
        $this->em()->flush();
        $this->em()->getConnection()->update('form_submissions', ['created_at' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s')], ['id' => $old->getId()]);

        $this->loginAs(UserRole::Admin);
        $this->client->request('GET', '/admin/forms');
        self::assertSame(2, $this->em()->getRepository(FormSubmission::class)->count([]), 'Nothing is deleted unless a retention period is set.');

        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['settings[retentionDays]' => '30']);
        self::assertResponseRedirects('/admin/settings');

        $this->client->request('GET', '/admin/forms');
        $remaining = $this->em()->getRepository(FormSubmission::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('Recent', $remaining[0]->getAnswers()[0]['value']);
    }

    public function testFormsAndInboxAreForEditorsAndAdminsOnly(): void
    {
        $form = $this->createContactForm();
        $submission = new FormSubmission($form, [['label' => 'Your name', 'value' => 'Ada']]);
        $this->em()->persist($submission);
        $this->em()->flush();

        $author = $this->loginAs(UserRole::Author);
        $post = $this->createPost('Mine', author: $author);
        $token = $this->csrfToken('/admin/media', '[data-uploader-csrf-value]', 'data-uploader-csrf-value');

        foreach (['/admin/forms', '/admin/forms/new', '/admin/forms/'.$form->getId().'/edit', '/admin/forms/'.$form->getId().'/submissions', '/admin/forms/submissions/'.$submission->getId(), '/admin/forms/'.$form->getId().'/export.csv'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('POST', '/admin/forms/'.$form->getId().'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/forms/submissions/'.$submission->getId().'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);

        // Authors can still place an existing form in their own post.
        $crawler = $this->client->request('GET', '/admin/posts/'.$post->getId().'/edit');
        $config = json_decode((string) $crawler->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);
        self::assertSame(['Contact form'], array_column($config['forms'], 'name'));
        self::assertNull($config['formsUrl']);

        // Deleting needs a valid token even for editors.
        $this->loginAs(UserRole::Editor);
        $this->client->request('POST', '/admin/forms/'.$form->getId().'/delete', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletedFormLeavesPagesIntact(): void
    {
        $form = $this->createContactForm();
        $this->createFormPage($form);
        $this->em()->persist(new FormSubmission($form, [['label' => 'Your name', 'value' => 'Ada']]));
        $this->em()->flush();
        $this->loginAs(UserRole::Editor);

        $token = $this->csrfToken('/admin/forms');
        $this->client->request('POST', '/admin/forms/'.$form->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/forms');
        self::assertSame(0, $this->em()->getRepository(FormSubmission::class)->count([]), 'Messages are deleted with their form.');

        $this->client->request('GET', '/contact');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form.site-form');
    }

    private function createContactForm(?string $notifyEmail = null): Form
    {
        $form = (new Form())
            ->setName('Contact form')
            ->setSubmitLabel('Send message')
            ->setSuccessMessage('Thanks, we will be in touch!')
            ->setNotifyEmail($notifyEmail)
            ->setFields([
                ['id' => 'fname0001', 'type' => 'text', 'label' => 'Your name', 'required' => true, 'options' => []],
                ['id' => 'femail001', 'type' => 'email', 'label' => 'Email', 'required' => true, 'options' => []],
                ['id' => 'fmessage1', 'type' => 'textarea', 'label' => 'Message', 'required' => true, 'options' => []],
                ['id' => 'ftopic001', 'type' => 'select', 'label' => 'Topic', 'required' => false, 'options' => ['Question', 'Praise']],
                ['id' => 'fnews0001', 'type' => 'checkbox', 'label' => 'Newsletter', 'required' => false, 'options' => []],
                ['id' => 'fconsent1', 'type' => 'consent', 'label' => 'I agree', 'required' => true, 'options' => []],
            ]);

        $this->em()->persist($form);
        $this->em()->flush();

        return $form;
    }

    private function createFormPage(Form $form): void
    {
        $page = $this->createPage('Contact', ContentStatus::Published);
        $page->setBlocks([['type' => 'form', 'data' => ['form' => $form->getId()]]]);
        $this->em()->flush();
    }

    /**
     * Sends the form found on the page the way a browser would.
     *
     * @param array<string, string> $answers keyed by a short name, see the map below
     * @param int                   $waited  seconds the visitor supposedly spent on the form
     */
    private function submit(Crawler $crawler, array $answers, string $honeypot = '', int $waited = 30, ?string $started = null): void
    {
        $ids = ['name' => 'fname0001', 'email' => 'femail001', 'message' => 'fmessage1', 'topic' => 'ftopic001', 'news' => 'fnews0001', 'consent' => 'fconsent1'];
        $fields = [];
        foreach ($answers as $name => $value) {
            $fields[$ids[$name]] = $value;
        }

        $this->client->request('POST', (string) $crawler->filter('form.site-form')->attr('action'), [
            'fields' => $fields,
            SpamGuard::HONEYPOT => $honeypot,
            SpamGuard::TIMESTAMP => $started ?? static::getContainer()->get(SpamGuard::class)->createTimestamp(time() - $waited),
        ], server: ['HTTP_REFERER' => 'http://localhost/contact']);
    }
}
