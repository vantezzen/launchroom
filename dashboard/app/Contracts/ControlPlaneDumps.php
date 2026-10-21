<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Backups\ControlPlaneDatabase;

/**
 * The dashboard's own database as a stream of bytes (docs/14 §13.4): the
 * final dump of a control-plane hand-over and its restore on the new
 * machine. The real one runs pg_dump / pg_restore
 * ({@see ControlPlaneDatabase}); tests swap it.
 */
interface ControlPlaneDumps
{
    /**
     * Hands the dump to $sink in pieces of $chunkBytes (at least one call).
     *
     * @param  callable(string): void  $sink
     * @return int bytes dumped
     */
    public function dump(callable $sink, int $chunkBytes, int $timeout): int;

    /**
     * Restores a dump given as pieces into $database (null: the configured one).
     *
     * @param  iterable<string>  $chunks
     * @return int bytes restored
     */
    public function restore(iterable $chunks, ?string $database, int $timeout): int;
}
