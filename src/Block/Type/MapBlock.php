<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

/**
 * A map from OpenStreetMap, which needs no API key. The map is only loaded
 * after the visitor clicks (see templates/blocks/map.html.twig).
 */
final class MapBlock extends AbstractBlockType
{
    /** Zoom choice => half the height of the shown area in degrees of latitude. */
    public const ZOOM_SPAN = ['street' => 0.003, 'town' => 0.02, 'region' => 0.2];

    public static function getType(): string
    {
        return 'map';
    }

    public function getIcon(): string
    {
        return '⌖';
    }

    public function getPosition(): int
    {
        return 90;
    }

    public function getFields(): array
    {
        return [
            Field::location('location', 'block.map.location')->required(),
            Field::text('label', 'block.map.label')->help('block.map.label_help'),
            Field::select('zoom', 'block.map.zoom', [
                'street' => 'block.map.zoom_street',
                'town' => 'block.map.zoom_town',
                'region' => 'block.map.zoom_region',
            ], 'street'),
        ];
    }
}
