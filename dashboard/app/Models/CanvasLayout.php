<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Node positions on the canvas, per environment; applied instantly (docs/06 §4).
 *
 * @property int $id
 * @property int $environment_id
 * @property array<string, array{x: float|int, y: float|int}> $positions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['environment_id', 'positions'])]
final class CanvasLayout extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'positions' => '{}',
    ];

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['positions' => 'array'];
    }
}
