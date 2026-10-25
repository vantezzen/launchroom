<?php

declare(strict_types=1);

namespace App\Models\Security;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One release cycle of a runtime, engine or distribution from
 * endoflife.date (docs/22 §4.6).
 *
 * @property string $product
 * @property string $cycle
 * @property string|null $latest
 * @property Carbon|null $latest_date
 * @property Carbon|null $release_date
 * @property Carbon|null $eol
 * @property bool $is_eol
 * @property Carbon $fetched_at
 */
#[Fillable(['product', 'cycle', 'latest', 'latest_date', 'release_date', 'eol', 'is_eol', 'fetched_at'])]
#[Table(name: 'lifecycle_releases', timestamps: false)]
#[WithoutIncrementing]
final class LifecycleRelease extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latest_date' => 'date',
            'release_date' => 'date',
            'eol' => 'date',
            'is_eol' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }
}
