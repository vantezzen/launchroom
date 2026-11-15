<?php

declare(strict_types=1);

namespace App\Contracts\Crypto;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A row whose secret columns are envelope-encrypted under its own data key
 * (docs/09 R13): `wrapped_dek` holds that key wrapped by the KEK with
 * {@see sealWrapContext()}, `kek_id` names the KEK, and every sealed column is
 * bound to the row by {@see sealAssociatedData()} so ciphertexts can't move
 * between rows, teams or columns. The strings are part of the stored format:
 * changing one makes existing rows undecryptable.
 *
 * Every implementing model is listed in App\Services\Crypto\SealedColumns::MODELS,
 * which KEK rotation walks (a test checks every `wrapped_dek` column is listed).
 */
interface SealedRow
{
    public function sealWrapContext(): string;

    /**
     * @throws \InvalidArgumentException when $column is not a sealed column of this row
     */
    public function sealAssociatedData(string $column): string;

    /**
     * The rows KEK rotation re-wraps.
     *
     * @return Builder<covariant Model>
     */
    public static function sealedRows(): Builder;
}
