<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Actions\Backups\RunControlPlaneBackup;
use App\Exceptions\DomainRuleViolation;
use App\Services\Backups\ControlPlaneKeyBundle;
use App\Services\Backups\DestinationCredentials;
use App\Services\Backups\EndpointGuard;
use App\Services\Backups\Process\PostgresClient;
use App\Services\Backups\Process\ProcessFailed;
use App\Services\Backups\Process\ResticCli;
use App\Services\Backups\Process\ResticTarget;
use App\Services\Backups\RecoveryKey;
use App\Services\Backups\RecoveryKitDocument;
use App\Services\Backups\RestoredControlPlaneRuns;
use App\Services\Backups\RestoredKeyFiles;
use App\Services\Backups\SnapshotTags;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Control-plane disaster recovery (docs/14 §5.2). `install.sh --restore`
 * runs this inside the new dashboard container; it is also usable by hand.
 * The full interface is documented in docs/14 §5.2 "Restore CLI".
 *
 * Secrets never come from argv: they are read from the environment
 * (LR_RESTORE_ACCESS_KEY_ID, LR_RESTORE_SECRET_ACCESS_KEY,
 * LR_RESTORE_REPOSITORY_PASSWORD, LR_RECOVERY_PASSPHRASE), from the recovery
 * kit file (--kit) or from --passphrase-file, or asked for interactively.
 *
 * Steps: list control-plane runs (pairs of `part:database` + `part:keys`
 * snapshots with the same `run:` tag) → open the key bundle with the
 * passphrase (fails early on a wrong passphrase) → pg_restore the database
 * dump into --database (never the running database unless asked) → write
 * the key files and restored.env to --keys-out.
 */
#[Signature('launchroom:restore
    {--kit= : Recovery kit file (destination, credentials, repository password)}
    {--endpoint= : S3 endpoint (overrides the kit)}
    {--bucket= : Bucket (overrides the kit)}
    {--prefix= : Key prefix (overrides the kit)}
    {--region= : Region (overrides the kit)}
    {--allow-http : Accept a plain-http endpoint (LAN/dev only)}
    {--passphrase-file= : File holding the recovery passphrase}
    {--list : List control-plane backups and exit}
    {--snapshot=latest : "latest", a run id (bkr_…) or a snapshot id prefix}
    {--database= : Database to restore into (default: the configured one)}
    {--create-database : Create the database if it does not exist}
    {--skip-database : Only restore the keys}
    {--keys-out= : Directory for the restored key files and restored.env}
    {--force : Restore into a database that already has tables; overwrite key files}
    {--json : Machine-readable output}')]
#[Description('Restore the control plane (database + keys) from its backup repository')]
final class RestoreControlPlaneCommand extends Command
{
    public function handle(ResticCli $restic): int
    {
        try {
            return $this->restore($restic);
        } catch (DomainRuleViolation|ProcessFailed|RuntimeException $e) {
            $this->fail($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->fail('The restore failed unexpectedly: '.$e::class);
        }
    }

    private function restore(ResticCli $restic): int
    {
        if (! $restic->available()) {
            throw new RuntimeException('restic is not installed in this image ('.config()->string('launchroom.backups.restic_binary').').');
        }

        $target = $this->target();
        $runs = $this->runs($restic, $target);

        if ($this->option('list')) {
            return $this->printRuns($runs);
        }

        if ($runs === []) {
            throw new RuntimeException('No complete control-plane backup (database and keys) was found in this repository.');
        }

        $run = $this->pick($runs);
        $this->say("Using backup {$run['run']} from {$run['time']}.");

        // Keys first: a wrong passphrase must fail before anything is changed.
        $keysSnapshot = $run['keys'];
        // Room for Headscale's base64-encoded state on top of the key files.
        $bundle = $restic->dumpToString($target, $keysSnapshot['id'], $keysSnapshot['paths'][0] ?? '/'.RunControlPlaneBackup::KEYS_FILE, intdiv(ControlPlaneKeyBundle::HEADSCALE_MAX_BYTES * 4, 3) + 4 * 1024 * 1024);
        [$plaintext, $binding] = RecoveryKey::openBound($bundle, $this->passphrase());

        // The key bundle names its run and database snapshot (review L5): a
        // database dump swapped in the bucket is refused before it is
        // restored.
        if ($binding !== null && ($binding['run'] !== $run['run'] || ! hash_equals($binding['databaseSnapshot'], $run['database']['id']))) {
            throw new RuntimeException('The database snapshot of this backup does not belong to its key bundle. The backup may have been tampered with; nothing was restored.');
        }

        $keys = ControlPlaneKeyBundle::parse($plaintext);
        $this->say('Recovery passphrase accepted; keys decrypted.');

        if ($binding === null) {
            // stderr: --json output stays parseable.
            $this->output->getErrorStyle()->writeln('<comment>This backup’s key bundle predates authenticated bundles: the database snapshot can’t be checked against it. Set the recovery passphrase again so new backups are authenticated.</comment>');
        }

        $database = null;
        $bytes = null;

        if (! $this->option('skip-database')) {
            $database = $this->databaseName();
            $this->prepareDatabase($database);

            $postgres = PostgresClient::fromConnection(config()->string('database.default'));
            $dbSnapshot = $run['database'];
            $this->say("Restoring the database into \"{$database}\"…");
            $bytes = $restic->dumpIntoProcess(
                $target,
                $dbSnapshot['id'],
                $dbSnapshot['paths'][0] ?? '/'.RunControlPlaneBackup::DATABASE_FILE,
                $postgres->restore($database),
                config()->integer('launchroom.backups.task_timeout_seconds'),
            );
            $this->say('Database restored ('.number_format($bytes).' bytes).');

            $settled = $this->onDatabase($database, static fn (string $connection): int => RestoredControlPlaneRuns::settle($connection, $run['run'], $dbSnapshot['id']));

            if ($settled > 0) {
                $this->say("Closed {$settled} control-plane backup run(s) that were in flight in the backup.");
            }
        }

        $written = $this->writeKeys($keys);

        if ($this->option('json')) {
            $this->line(json_encode([
                'run' => $run['run'],
                'time' => $run['time'],
                'database' => $database,
                'databaseBytes' => $bytes,
                'keysOut' => $this->stringOption('keys-out'),
                'files' => $written,
                'env' => array_keys($keys['env']),
                'headscale' => array_keys($keys['headscale']),
                'appUrl' => $keys['appUrl'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Restore complete.');

            if ($written === []) {
                $this->warn('Keys were decrypted but not written (pass --keys-out=<dir>). Without them the restored data cannot be decrypted.');
            } else {
                $this->line('Next: put the files from '.$this->stringOption('keys-out').' in place (data/keys and .env), run `php artisan migrate --force`, and start the stack.');
            }
        }

        return self::SUCCESS;
    }

    private function target(): ResticTarget
    {
        $kit = [];

        if (is_string($this->option('kit')) && $this->option('kit') !== '') {
            $path = $this->option('kit');

            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("The recovery kit {$path} can't be read.");
            }

            $kit = RecoveryKitDocument::parse((string) file_get_contents($path));
        }

        $endpoint = $this->value('endpoint', $kit['endpoint'] ?? null, 'restore.endpoint', 'S3 endpoint (https://…)', false);
        $bucket = $this->value('bucket', $kit['bucket'] ?? null, 'restore.bucket', 'Bucket', false);
        $prefix = $this->value('prefix', $kit['prefix'] ?? '', 'restore.prefix', null, false) ?? '';
        $region = $this->value('region', $kit['region'] ?? null, 'restore.region', null, false);

        $guard = resolve(EndpointGuard::class);
        // Grammar only: the operator restoring their own server is not an SSRF risk.
        $endpoint = $guard->check((string) $endpoint, true);

        if (! str_starts_with($endpoint, 'https://') && ! $this->option('allow-http')) {
            throw new RuntimeException('The endpoint uses plain http; pass --allow-http if that is intended (LAN/dev only).');
        }

        $prefix = trim($prefix, '/');
        $guard->checkLocation((string) $bucket, $prefix, $region === '' ? null : $region);

        $credentials = new DestinationCredentials(
            (string) $this->value(null, $kit['access-key-id'] ?? null, 'restore.access_key_id', 'S3 access key id', true),
            (string) $this->value(null, $kit['secret-access-key'] ?? null, 'restore.secret_access_key', 'S3 secret access key', true),
            (string) $this->value(null, $kit['repository-password'] ?? null, 'restore.repository_password', 'Restic repository password (from the recovery kit)', true),
        );

        $repository = 's3:'.$endpoint.'/'.$bucket.($prefix === '' ? '' : '/'.$prefix);

        return new ResticTarget($repository, $region === '' ? null : $region, $credentials);
    }

    /**
     * Precedence: option > environment (config launchroom.restore.*) > kit > prompt.
     */
    private function value(?string $option, ?string $fromKit, string $configKey, ?string $prompt, bool $secret): ?string
    {
        $value = $option === null ? null : $this->option($option);

        if (! is_string($value) || $value === '') {
            $env = config("launchroom.{$configKey}");
            $value = is_string($env) && $env !== '' ? $env : $fromKit;
        }

        if (($value === null || $value === '') && $prompt !== null) {
            if (! $this->input->isInteractive()) {
                throw new RuntimeException("Missing value: {$prompt}.");
            }

            $value = $secret ? $this->secret($prompt) : $this->ask($prompt);
        }

        return is_string($value) ? trim($value) : null;
    }

    private function passphrase(): string
    {
        $file = $this->option('passphrase-file');

        if (is_string($file) && $file !== '') {
            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException("The passphrase file {$file} can't be read.");
            }

            return rtrim((string) file_get_contents($file), "\r\n");
        }

        $env = config('launchroom.restore.passphrase');

        if (is_string($env) && $env !== '') {
            return $env;
        }

        if (! $this->input->isInteractive()) {
            throw new RuntimeException('Missing the recovery passphrase (LR_RECOVERY_PASSPHRASE or --passphrase-file).');
        }

        $passphrase = $this->secret('Recovery passphrase');

        return is_string($passphrase) ? $passphrase : '';
    }

    /**
     * Complete runs (both parts present), newest first.
     *
     * @return list<array{run: string, time: string, database: array{id: string, time: string, tags: list<string>, paths: list<string>}, keys: array{id: string, time: string, tags: list<string>, paths: list<string>}}>
     */
    private function runs(ResticCli $restic, ResticTarget $target): array
    {
        $parts = [];

        foreach ($restic->snapshots($target, ['kind:control-plane']) as $snapshot) {
            $run = SnapshotTags::value($snapshot['tags'], 'run');
            $part = SnapshotTags::value($snapshot['tags'], 'part');

            if ($run !== null && ($part === 'database' || $part === 'keys')) {
                $parts[$run][$part] ??= $snapshot;
            }
        }

        $runs = [];

        foreach ($parts as $run => $snapshots) {
            if (isset($snapshots['database'], $snapshots['keys'])) {
                $runs[] = ['run' => $run, 'time' => $snapshots['database']['time'], 'database' => $snapshots['database'], 'keys' => $snapshots['keys']];
            }
        }

        usort($runs, static fn (array $a, array $b): int => strcmp($b['time'], $a['time']));

        return $runs;
    }

    /**
     * @param  list<array{run: string, time: string, database: array{id: string, time: string, tags: list<string>, paths: list<string>}, keys: array{id: string, time: string, tags: list<string>, paths: list<string>}}>  $runs
     * @return array{run: string, time: string, database: array{id: string, time: string, tags: list<string>, paths: list<string>}, keys: array{id: string, time: string, tags: list<string>, paths: list<string>}}
     */
    private function pick(array $runs): array
    {
        $wanted = $this->stringOption('snapshot');

        if ($wanted === '' || $wanted === 'latest') {
            return $runs[0];
        }

        foreach ($runs as $run) {
            if ($run['run'] === $wanted || (preg_match('/^[a-f0-9]{8,64}$/D', $wanted) === 1 && (str_starts_with($run['database']['id'], $wanted) || str_starts_with($run['keys']['id'], $wanted)))) {
                return $run;
            }
        }

        throw new RuntimeException("No complete control-plane backup matches \"{$wanted}\". Use --list to see them.");
    }

    /**
     * @param  list<array{run: string, time: string, database: array{id: string, time: string, tags: list<string>, paths: list<string>}, keys: array{id: string, time: string, tags: list<string>, paths: list<string>}}>  $runs
     */
    private function printRuns(array $runs): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(array_map(static fn (array $r): array => [
                'run' => $r['run'],
                'time' => $r['time'],
                'databaseSnapshot' => $r['database']['id'],
                'keysSnapshot' => $r['keys']['id'],
                'reason' => SnapshotTags::value($r['database']['tags'], 'reason'),
            ], $runs), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Run', 'Time (UTC)', 'Reason', 'Database snapshot', 'Keys snapshot'], array_map(static fn (array $r): array => [
            $r['run'], $r['time'], SnapshotTags::value($r['database']['tags'], 'reason') ?? '', substr($r['database']['id'], 0, 8), substr($r['keys']['id'], 0, 8),
        ], $runs));

        return self::SUCCESS;
    }

    private function databaseName(): string
    {
        $option = $this->option('database');
        $name = is_string($option) && $option !== '' ? $option : config()->string('database.connections.'.config()->string('database.default').'.database');

        return PostgresClient::database($name);
    }

    private function prepareDatabase(string $database): void
    {
        $exists = DB::selectOne('SELECT 1 AS present FROM pg_database WHERE datname = ?', [$database]) !== null;

        if (! $exists) {
            if (! $this->option('create-database')) {
                throw new RuntimeException("The database \"{$database}\" does not exist (pass --create-database).");
            }

            // The name is grammar-checked (letters, digits, underscore); identifiers can't be bound.
            DB::statement('CREATE DATABASE "'.$database.'"');

            return;
        }

        $tables = DB::connection()->getDriverName() === 'pgsql'
            ? $this->tableCount($database)
            : 0;

        if ($tables > 0 && ! $this->option('force')) {
            if (! $this->input->isInteractive() || ! $this->confirm("\"{$database}\" already has {$tables} tables. Replace its contents with the backup?", false)) {
                throw new RuntimeException("\"{$database}\" is not empty; pass --force to replace its contents.");
            }
        }
    }

    private function tableCount(string $database): int
    {
        $row = $this->onDatabase($database, static fn (string $connection): mixed => DB::connection($connection)->selectOne("SELECT count(*) AS n FROM information_schema.tables WHERE table_schema = 'public'"));

        return is_object($row) && is_numeric($row->n ?? null) ? (int) $row->n : 0;
    }

    /**
     * Runs $callback with the name of a connection to $database: the
     * default one, or a temporary connection to another database.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    private function onDatabase(string $database, callable $callback): mixed
    {
        $default = config()->string('database.default');

        if ($database === config()->string('database.connections.'.$default.'.database')) {
            return $callback($default);
        }

        config(['database.connections.launchroom_restore_target' => [...config()->array('database.connections.'.$default), 'database' => $database]]);

        try {
            return $callback('launchroom_restore_target');
        } finally {
            DB::purge('launchroom_restore_target');
        }
    }

    /**
     * @param  array{appUrl: string|null, env: array<string, string>, files: array<string, string>, headscale: array<string, string>}  $keys
     * @return list<string> written paths
     */
    private function writeKeys(array $keys): array
    {
        $out = $this->option('keys-out');

        if (! is_string($out) || $out === '') {
            return [];
        }

        return RestoredKeyFiles::write($keys, $out, $this->option('force') === true);
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }

    private function say(string $message): void
    {
        if (! $this->option('json')) {
            $this->line($message);
        }
    }
}
