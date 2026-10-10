<?php

namespace App\DragDrop;

/**
 * A move that the structure does not allow. The message is a translation key (messages domain).
 */
final class InvalidMoveException extends \RuntimeException
{
    public const INTO_ITSELF = 'dnd.error.into_itself';
    public const TOO_DEEP = 'dnd.error.too_deep';
    public const WRONG_PARENT = 'dnd.error.wrong_parent';
}
