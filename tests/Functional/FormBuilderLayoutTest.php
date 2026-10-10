<?php

namespace App\Tests\Functional;

use App\Entity\Form;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Service\FormDefinition;
use App\Tests\AppWebTestCase;

/**
 * Rows of fields and the order of choices, as the server stores and shows them.
 * Dragging in the form builder is covered by tests/Browser/FormBuilderDragDropTest.
 */
final class FormBuilderLayoutTest extends AppWebTestCase
{
    public function testBuilderSavesFieldsThatShareARow(): void
    {
        $this->loginAs(UserRole::Editor);

        $crawler = $this->client->request('GET', '/admin/forms/new');
        self::assertResponseIsSuccessful();
        $palette = $crawler->filter('[data-form-builder-target="palette"]');
        self::assertSame('palette', $palette->attr('data-controller'));
        self::assertSame('form-fields', $palette->attr('data-palette-group-value'));

        $this->client->submitForm('Save', [
            'form[name]' => 'Sign up',
            'form[fields]' => $this->json([
                ['type' => 'text', 'label' => 'First name', 'join' => true],
                ['type' => 'text', 'label' => 'Last name', 'join' => true],
                ['type' => 'email', 'label' => 'Email', 'join' => true],
                ['type' => 'text', 'label' => 'Phone', 'join' => true],
                ['type' => 'select', 'label' => 'Size', 'options' => ['L', 'S', 'M', 'S', '']],
                ['type' => 'textarea', 'label' => 'Note', 'join' => 'yes'],
            ]),
        ]);
        self::assertResponseRedirects();

        $form = $this->em()->getRepository(Form::class)->findOneBy(['name' => 'Sign up']);
        self::assertInstanceOf(Form::class, $form);
        self::assertSame([false, true, true, false, false, true], array_column($form->getFields(), 'join'), 'The first field starts a row, and a row never holds more than three.');
        self::assertSame(['L', 'S', 'M'], $form->getFields()[4]['options'], 'Choices keep the order they were given in.');
        self::assertSame([3, 1, 2], array_map(\count(...), $form->getRows()));

        // The builder gets the rows back for the next edit.
        $config = json_decode((string) $this->client->request('GET', '/admin/forms/'.$form->getId().'/edit')->filter('[data-controller="form-builder"]')->attr('data-form-builder-config-value'), true);
        self::assertSame([false, true, true, false, false, true], array_column($config['fields'], 'join'));
        foreach (['palette_add', 'list_row', 'list_new_row', 'drop_new_row', 'list_options', 'option_add'] as $key) {
            self::assertArrayHasKey($key, $config['i18n']);
        }
    }

    public function testWebsiteShowsFieldsThatShareARowSideBySide(): void
    {
        $form = (new Form())->setName('Contact')->setFields([
            ['id' => 'fname0001', 'type' => 'text', 'label' => 'Name', 'required' => true, 'options' => [], 'join' => false],
            ['id' => 'femail001', 'type' => 'email', 'label' => 'Email', 'required' => true, 'options' => [], 'join' => true],
            ['id' => 'fmessage1', 'type' => 'textarea', 'label' => 'Message', 'required' => false, 'options' => [], 'join' => false],
        ]);
        $this->em()->persist($form);
        $this->em()->flush();
        $page = $this->createPage('Contact', ContentStatus::Published);
        $page->setBlocks([['type' => 'form', 'data' => ['form' => $form->getId()]]]);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/contact');
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('.site-form__row');
        self::assertCount(1, $rows, 'Only fields that share a row are wrapped.');
        self::assertSame('site-form__row site-form__row--2', $rows->attr('class'));
        self::assertSame(['Name *', 'Email *'], $rows->filter('label')->each(static fn ($label): string => trim((string) preg_replace('/\s+/', ' ', $label->text()))));
        self::assertCount(3, $crawler->filter('.site-form__field'));
        self::assertCount(0, $rows->filter('textarea'));

        // The layout changes nothing about the fields themselves.
        self::assertCount(2, $rows->filter('input[required]'));
        self::assertSame('email', $rows->filter('input[name="fields[femail001]"]')->attr('type'));
    }

    public function testFormsWithoutRowsLookAsBefore(): void
    {
        // Forms saved before fields could share a row have no "join" at all.
        $fields = [
            ['id' => 'fname0001', 'type' => 'text', 'label' => 'Name', 'required' => true, 'options' => []],
            ['id' => 'femail001', 'type' => 'email', 'label' => 'Email', 'required' => true, 'options' => []],
        ];

        self::assertSame([[$fields[0]], [$fields[1]]], FormDefinition::rows($fields));
    }
}
