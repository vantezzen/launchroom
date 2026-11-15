<?php

declare(strict_types=1);

namespace App\Actions\Crypto;

use App\Contracts\Crypto\SealedRow;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\SealedColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * KEK rotation (docs/09 R13, `launchroom:keys:rotate-kek`): the data key of
 * every sealed row ({@see SealedColumns::MODELS}) is unwrapped with the
 * current KEK and wrapped with the new one, and its `kek_id` updated, in one
 * transaction. Values encrypted under the data keys don't change. Rows
 * already wrapped by the new KEK are skipped, so a re-run with the same new
 * key is harmless; a row wrapped by an unknown KEK aborts everything.
 */
final readonly class RotateKek
{
    /**
     * @return array{rewrapped: int, skipped: int}
     */
    public function handle(Envelope $current, Envelope $next): array
    {
        if (hash_equals($current->kekId(), $next->kekId())) {
            throw new RuntimeException('The new KEK is the current KEK.');
        }

        return DB::transaction(function () use ($current, $next): array {
            $counts = ['rewrapped' => 0, 'skipped' => 0];

            foreach (SealedColumns::MODELS as $model) {
                foreach ($model::sealedRows()->orderBy('id')->lockForUpdate()->get() as $row) {
                    $this->rotateRow($row, $current, $next, $counts);
                }
            }

            return $counts;
        });
    }

    /**
     * @param  array{rewrapped: int, skipped: int}  $counts
     */
    private function rotateRow(Model&SealedRow $row, Envelope $current, Envelope $next, array &$counts): void
    {
        $kekId = $row->getAttribute('kek_id');
        $kekId = is_string($kekId) ? $kekId : '';

        if (hash_equals($next->kekId(), $kekId)) {
            $counts['skipped']++;

            return;
        }

        if (! hash_equals($current->kekId(), $kekId)) {
            $id = $row->getKey();

            throw new RuntimeException(class_basename($row).' #'.(is_scalar($id) ? (string) $id : '?')." is wrapped by an unknown KEK ({$kekId}); nothing was changed.");
        }

        SealedColumns::rewrap($row, $current, $next);
        $row->save();
        $counts['rewrapped']++;
    }
}
