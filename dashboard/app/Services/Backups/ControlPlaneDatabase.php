<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Contracts\ControlPlaneDumps;
use App\Services\Backups\Process\PostgresClient;
use App\Services\Backups\Process\ProcessFailed;

/**
 * The dashboard's own database as a stream (docs/14 §13.4): the final dump
 * of a control-plane hand-over, and its restore on the new machine. The
 * process runs through {@see PostgresClient} (argv only, the password in
 * the environment); callers handle the bytes (encryption, transport).
 */
final class ControlPlaneDatabase implements ControlPlaneDumps
{
    /**
     * @param  callable(string): void  $sink
     *
     * @throws ProcessFailed
     */
    public function dump(callable $sink, int $chunkBytes, int $timeout): int
    {
        $connection = config()->string('database.default');

        return PostgresClient::fromConnection($connection)->dumpInChunks(self::name(), $sink, $chunkBytes, $timeout);
    }

    /**
     * @param  iterable<string>  $chunks
     *
     * @throws ProcessFailed
     */
    public function restore(iterable $chunks, ?string $database, int $timeout): int
    {
        return PostgresClient::fromConnection(config()->string('database.default'))->restoreFrom(PostgresClient::database($database ?? self::name()), $chunks, $timeout);
    }

    public static function name(): string
    {
        $connection = config()->string('database.default');

        return config()->string("database.connections.{$connection}.database");
    }
}
