<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * Node positions of one environment's canvas, keyed by service public id.
 * Saved instantly, never staged (docs/06 §4).
 */
final class CanvasLayoutData extends Data
{
    /**
     * @param  array<string, CanvasPositionData>  $positions
     */
    public function __construct(
        public array $positions,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'positions' => ['present', 'array', 'max:500'],
            'positions.*' => ['array:x,y'],
            'positions.*.x' => ['required', 'numeric', 'between:-100000,100000'],
            'positions.*.y' => ['required', 'numeric', 'between:-100000,100000'],
        ];
    }
}
