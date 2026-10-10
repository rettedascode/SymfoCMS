<?php

namespace App\Tests\Browser;

use App\Entity\Form;
use App\Enum\UserRole;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;

final class FormBuilderDragDropTest extends BrowserTestCase
{
    public function testFieldsAreDraggedInPlacedSideBySideAndSaved(): void
    {
        $this->logIn(UserRole::Editor);
        $form = $this->createForm();

        $this->browser->request('GET', '/admin/forms/'.$form->getId().'/edit');
        $this->browser->waitFor('.form-field [data-dnd-handle]');
        self::assertSame(['text', 'email', 'select'], $this->rows());
        self::assertCount(6, $this->findAll('.palette__button'));

        // Drag "Short text" from the palette onto the right half of the first field: they share a row.
        $this->drag($this->paletteEntry('Short text'), $this->row(1), 0.5);
        self::assertSame(['text+text', 'email', 'select'], $this->sortedRows());

        // Clicking a field type adds it as a row of its own at the end.
        $this->js('arguments[0].click()', $this->paletteEntry('Consent'));
        usleep(300_000);
        self::assertSame(['text+text', 'email', 'select', 'consent'], $this->sortedRows());

        // Drag the email field by its handle into the first row: three side by side.
        $this->drag($this->find('.form-row .form-field[data-field-type="email"] [data-dnd-handle]'), $this->row(1), 0.5);
        self::assertSame(['email+text+text', 'select', 'consent'], $this->sortedRows());

        // Label the new fields and save: the rows are stored.
        $this->js('document.querySelectorAll(".form-field__label").forEach((input, index) => { if (!input.value) { input.value = "Field " + index; input.dispatchEvent(new Event("input", {bubbles: true})); } })');
        $this->js('arguments[0].click()', $this->find('.form-actions button[type="submit"]'));
        $this->browser->waitFor('.flash--success');

        $this->em()->clear();
        $stored = $this->em()->find(Form::class, $form->getId());
        self::assertInstanceOf(Form::class, $stored);
        self::assertSame([3, 1, 1], array_map(\count(...), $stored->getRows()));
        self::assertSame('consent', $stored->getFields()[4]['type']);
        $this->assertNoJavaScriptErrors();
    }

    public function testFieldsAndChoicesMoveWithTheKeyboard(): void
    {
        $this->logIn(UserRole::Editor);
        $form = $this->createForm();

        $this->browser->request('GET', '/admin/forms/'.$form->getId().'/edit');
        $this->browser->waitFor('.form-field [data-dnd-handle]');

        // Enter on a palette entry adds the field at the end.
        $this->press($this->paletteEntry('Checkbox'), WebDriverKeys::ENTER);
        self::assertSame(['text', 'email', 'select', 'checkbox'], $this->rows());
        self::assertStringContainsString('“Checkbox” was added', $this->announcement());

        // Space picks the email field up; one step up leads into the row of the name field.
        $handle = $this->find('.form-field[data-field-type="email"] [data-dnd-handle]');
        $this->press($handle, WebDriverKeys::SPACE);
        self::assertStringContainsString('picked up. Position 1 of 1 in Row 2 of the form', $this->announcement());
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_UP);
        self::assertStringContainsString('A new row', $this->announcement(), 'First the gap between the two rows ...');
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_UP);
        self::assertStringContainsString('Position 2 of 2 in Row 1 of the form', $this->announcement(), '... then the place next to the name field.');
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::SPACE);
        usleep(350_000);
        self::assertSame(['text+email', 'select', 'checkbox'], $this->rows());

        // Ctrl+Z takes the field out of the row again.
        $this->js('document.activeElement.blur()');
        $this->shortcut(WebDriverKeys::CONTROL, 'z');
        self::assertSame(['text', 'email', 'select', 'checkbox'], $this->rows());

        // Choices of the selection field: the first one moves down.
        self::assertSame(['Red', 'Green', 'Blue'], $this->choices());
        $this->press($this->find('.form-options__item:nth-child(1) [data-dnd-handle]'), WebDriverKeys::SPACE, WebDriverKeys::ARROW_DOWN, WebDriverKeys::SPACE);
        self::assertSame(['Green', 'Red', 'Blue'], $this->choices());
        self::assertStringContainsString('Red dropped. Position 2 of 3 in Choices of “Colour”', $this->announcement());

        // The menu on the handle works for choices too.
        $this->press($this->find('.form-options__item:nth-child(3) [data-dnd-handle]'), WebDriverKeys::ENTER);
        $this->browser->waitFor('.dnd-menu');
        $this->js('arguments[0].click()', $this->find('.dnd-menu__item:nth-child(1)'));
        usleep(350_000);
        self::assertSame(['Green', 'Blue', 'Red'], $this->choices());

        $this->js('document.querySelectorAll(".form-field__label").forEach((input) => { if (!input.value) { input.value = "Newsletter"; input.dispatchEvent(new Event("input", {bubbles: true})); } })');
        $this->js('arguments[0].click()', $this->find('.form-actions button[type="submit"]'));
        $this->browser->waitFor('.flash--success');
        $this->em()->clear();
        $stored = $this->em()->find(Form::class, $form->getId());
        self::assertInstanceOf(Form::class, $stored);
        self::assertSame(['Green', 'Blue', 'Red'], $stored->getFields()[2]['options']);
        $this->assertNoJavaScriptErrors();
    }

    private function createForm(): Form
    {
        $form = (new Form())->setName('Order')->setSubmitLabel('Send')->setSuccessMessage('Thank you.')->setFields([
            ['id' => 'fname0001', 'type' => 'text', 'label' => 'Name', 'required' => true, 'options' => [], 'join' => false],
            ['id' => 'femail001', 'type' => 'email', 'label' => 'Email', 'required' => true, 'options' => [], 'join' => false],
            ['id' => 'fcolour01', 'type' => 'select', 'label' => 'Colour', 'required' => false, 'options' => ['Red', 'Green', 'Blue'], 'join' => false],
        ]);
        $this->em()->persist($form);
        $this->em()->flush();

        return $form;
    }

    /**
     * @return list<string> one entry per row, the types of its fields joined with "+"
     */
    private function rows(): array
    {
        $rows = (array) $this->js('return [...document.querySelectorAll(".form-row")].map((row) => [...row.querySelectorAll(":scope > .form-field")].map((field) => field.dataset.fieldType).join("+"))');

        return array_values(array_map(strval(...), $rows));
    }

    /**
     * The rows with the fields of each row in alphabetical order: whether a dragged field lands
     * left or right of another depends on a few pixels, that it shares the row does not.
     *
     * @return list<string>
     */
    private function sortedRows(): array
    {
        return array_map(static function (string $row): string {
            $types = explode('+', $row);
            sort($types);

            return implode('+', $types);
        }, $this->rows());
    }

    /**
     * @return list<string>
     */
    private function choices(): array
    {
        $choices = (array) $this->js('return [...document.querySelectorAll(".form-options__item input")].map((input) => input.value)');

        return array_values(array_map(strval(...), $choices));
    }

    private function row(int $number): WebDriverElement
    {
        $this->js('document.querySelectorAll("[data-test-row]").forEach((row) => row.removeAttribute("data-test-row")); document.querySelectorAll(".form-row")[arguments[0] - 1].setAttribute("data-test-row", "")', $number);

        return $this->find('.form-row[data-test-row]');
    }

    private function paletteEntry(string $label): WebDriverElement
    {
        $this->js('document.querySelectorAll("[data-test-entry]").forEach((button) => button.removeAttribute("data-test-entry")); [...document.querySelectorAll(".palette__button")].find((button) => button.lastElementChild.textContent === arguments[0])?.setAttribute("data-test-entry", "")', $label);

        return $this->find('.palette__button[data-test-entry]');
    }
}
