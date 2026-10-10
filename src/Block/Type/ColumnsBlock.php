<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

/**
 * Two or three columns of blocks next to each other.
 *
 * "left" and "right" are always there; "middle" only counts when it holds blocks.
 * "widths" says how the available width is shared, in twelfths ("4-8" is one third
 * and two thirds). Without it, or when it does not fit the number of columns, all
 * columns are equally wide.
 */
final class ColumnsBlock extends AbstractBlockType
{
    /** Parts the width is divided into. */
    public const GRID = 12;

    /** No column gets narrower than this many parts (a quarter of the width). */
    public const MIN_WIDTH = 3;

    public static function getType(): string
    {
        return 'columns';
    }

    public function getIcon(): string
    {
        return '▥';
    }

    public function getPosition(): int
    {
        return 70;
    }

    public function getFields(): array
    {
        return [
            Field::blocks('left', 'block.columns.left'),
            Field::blocks('middle', 'block.columns.middle'),
            Field::blocks('right', 'block.columns.right'),
            Field::select('widths', 'block.columns.widths', array_combine(self::widthChoices(), self::widthChoices()), ''),
        ];
    }

    /**
     * Every way to share the width between two or three columns, e.g. "6-6", "4-8", "3-4-5".
     *
     * @return list<string>
     */
    public static function widthChoices(): array
    {
        $choices = [];
        $max = self::GRID - self::MIN_WIDTH;
        for ($first = self::MIN_WIDTH; $first <= $max; ++$first) {
            $choices[] = $first.'-'.(self::GRID - $first);
        }
        for ($first = self::MIN_WIDTH; $first <= $max - self::MIN_WIDTH; ++$first) {
            for ($second = self::MIN_WIDTH; $first + $second <= $max; ++$second) {
                $choices[] = $first.'-'.$second.'-'.(self::GRID - $first - $second);
            }
        }

        return $choices;
    }
}
