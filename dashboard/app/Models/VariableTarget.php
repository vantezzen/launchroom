<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One target of a variable: a specific environment, or "all previews"
 * (optionally only for branches matching `branch_pattern`) (docs/06 §3.1).
 *
 * @property int $id
 * @property int $variable_id
 * @property int|null $environment_id
 * @property bool $all_previews
 * @property string|null $branch_pattern
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['variable_id', 'environment_id', 'all_previews', 'branch_pattern'])]
final class VariableTarget extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'all_previews' => false,
    ];

    /**
     * @return BelongsTo<Variable, $this>
     */
    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }

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
        return ['all_previews' => 'boolean'];
    }
}
