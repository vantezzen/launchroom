<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Contracts\Crypto\SealedRow;
use Illuminate\Database\Eloquent\Builder;

/**
 * Default rotation scope of an {@see SealedRow}: every
 * row that has a data key.
 */
trait HasSealedSecret
{
    /**
     * @return Builder<self>
     */
    public static function sealedRows(): Builder
    {
        return self::query()->whereNotNull('wrapped_dek');
    }
}
