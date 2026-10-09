<?php

namespace App\Block;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A kind of content block. Every class implementing this interface is picked
 * up automatically and offered in the editor.
 *
 * In most cases, extend {@see AbstractBlockType} and only declare the fields.
 */
#[AutoconfigureTag('app.block_type')]
interface BlockTypeInterface
{
    /**
     * Unique identifier stored with the content, e.g. "heading". Never change it once content exists.
     */
    public static function getType(): string;

    /**
     * Name shown in the editor: a translation key or plain text.
     */
    public function getLabel(): string;

    /**
     * One or two characters shown next to the label.
     */
    public function getIcon(): string;

    /**
     * @return list<Field>
     */
    public function getFields(): array;

    /**
     * Checks the data of one block before content is saved.
     *
     * The data has already been cleaned up: every field declared in getFields()
     * is present and has the right type. Return an empty array when everything is fine.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string> field name => message for the editor (translation key or plain text)
     */
    public function validate(array $data): array;

    /**
     * Twig template that renders the block on the public website. It receives the variable "data".
     */
    public function getTemplate(): string;

    /**
     * Sort order in the "add block" menu, lowest first.
     */
    public function getPosition(): int;
}
