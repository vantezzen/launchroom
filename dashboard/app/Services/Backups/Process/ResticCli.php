<?php

declare(strict_types=1);

namespace App\Services\Backups\Process;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use JsonException;
use SensitiveParameter;
use Symfony\Component\Process\Process;

/**
 * restic on the control plane (docs/14 §2 "Control plane", §3 "test
 * destination"). The control-plane backup runs inside the dashboard
 * container, which ships a pinned, checksum-verified restic and the
 * Postgres 18 client tools (deploy/images/dashboard/Dockerfile).
 *
 * Process execution is confined to this namespace (docs/10 §2.2 rule 8,
 * enforced by tests/Arch): argv arrays only, never a shell (docs/09 R1);
 * credentials only through the environment, never argv (docs/14 §7); every
 * piece of output we surface is masked (docs/09 R16). Only queued jobs and
 * console commands call this, never a request.
 *
 * restic exit codes (≥ 0.17): 0 ok, 1 fatal, 3 some files unreadable,
 * 10 repository does not exist, 11 lock failed, 12 wrong password, 130 interrupted.
 */
final readonly class ResticCli
{
    public const int EXIT_REPOSITORY_MISSING = 10;

    public const int EXIT_LOCKED = 11;

    public const int EXIT_WRONG_PASSWORD = 12;

    /** Upper bound of one `restic init` (its timeout) plus the config check. */
    private const int INIT_LOCK_SECONDS = 330;

    /** Environment restic must never inherit from the dashboard container. */
    private const array SCRUBBED_ENV = [
        'RESTIC_REPOSITORY', 'RESTIC_REPOSITORY_FILE', 'RESTIC_PASSWORD_FILE', 'RESTIC_PASSWORD_COMMAND',
        'RESTIC_KEY_HINT', 'RESTIC_FROM_REPOSITORY', 'RESTIC_FROM_PASSWORD', 'AWS_PROFILE', 'AWS_SESSION_TOKEN',
        'AWS_SHARED_CREDENTIALS_FILE', 'AWS_CONFIG_FILE', 'AWS_WEB_IDENTITY_TOKEN_FILE', 'HTTP_PROXY', 'HTTPS_PROXY',
        'http_proxy', 'https_proxy', 'ALL_PROXY', 'all_proxy',
    ];

    public function __construct(
        private string $binary,
        private string $cacheDir,
        private int $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config()->string('launchroom.backups.restic_binary'),
            config()->string('launchroom.backups.restic_cache_dir'),
            config()->integer('launchroom.backups.task_timeout_seconds'),
        );
    }

    public function available(): bool
    {
        return is_file($this->binary) && is_executable($this->binary);
    }

    /**
     * Runs one restic command and buffers its output.
     *
     * @param  list<string>  $args
     */
    public function run(ResticTarget $target, array $args, ?int $timeoutSeconds = null): ProcessOutcome
    {
        $process = $this->process($target, $args, $timeoutSeconds);
        $process->run();

        return ProcessOutcome::from($process, $target->credentials->secrets());
    }

    /**
     * `restic cat config`; initialises the repository when it doesn't exist.
     *
     * Initialisation is serialised per repository (cache lock): two
     * concurrent `restic init` runs against an empty bucket (the destination
     * test queued on create and a backup started right away) each write a
     * key and a config, leaving a key that can't decrypt the config, which
     * restic then reports as "ciphertext verification failed" forever.
     * Under the lock the config is checked again before `init`.
     *
     * @return 'existing'|'initialized'
     *
     * @throws ProcessFailed
     */
    public function openOrInit(ResticTarget $target): string
    {
        if ($this->openExisting($target)) {
            return 'existing';
        }

        try {
            /** @var 'existing'|'initialized' $result */
            $result = Cache::lock('launchroom:restic-init:'.hash('sha256', $target->repository), self::INIT_LOCK_SECONDS)
                ->block(self::INIT_LOCK_SECONDS, function () use ($target): string {
                    if ($this->openExisting($target)) {
                        return 'existing';
                    }

                    $init = $this->run($target, ['init'], 300);

                    if ($init->exitCode !== 0) {
                        throw ProcessFailed::restic('initialise the repository', $init);
                    }

                    return 'initialized';
                });

            return $result;
        } catch (LockTimeoutException) {
            throw ProcessFailed::message('restic could not initialise the repository: another initialisation is still running; try again later.');
        }
    }

    /**
     * @throws ProcessFailed when the repository exists but can't be opened
     */
    private function openExisting(ResticTarget $target): bool
    {
        $config = $this->run($target, ['cat', 'config'], 120);

        if ($config->exitCode === 0) {
            return true;
        }

        if ($config->exitCode !== self::EXIT_REPOSITORY_MISSING) {
            throw ProcessFailed::restic('open the repository', $config);
        }

        return false;
    }

    /**
     * Streams $producer's stdout into `restic backup --stdin` and checks BOTH
     * exit codes: a failed producer fails the backup even if restic stored a
     * (tiny, useless) snapshot, which is then forgotten (docs/14 §2).
     *
     * @param  list<string>  $tags
     *
     * @throws ProcessFailed
     */
    public function backupFromProcess(ResticTarget $target, Process $producer, string $filename, array $tags, int $producerTimeout): BackupOutcome
    {
        $args = ['backup', '--stdin', '--stdin-filename', $filename, '--host', 'launchroom', '--json'];

        foreach ($tags as $tag) {
            $args[] = '--tag';
            $args[] = $tag;
        }

        $restic = $this->process($target, $args, null);
        $producer->setTimeout($producerTimeout);
        $producer->start();

        $bytes = 0;
        $restic->setInput((function () use ($producer, &$bytes): \Generator {
            foreach ($producer->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
                $bytes += strlen($chunk);

                yield $chunk;
            }
        })());
        $restic->run();
        $producer->wait();

        $outcome = ProcessOutcome::from($restic, $target->credentials->secrets());
        $summary = $this->summary($outcome->stdout);

        if (! $producer->isSuccessful()) {
            if ($summary !== null) {
                $this->run($target, ['forget', $summary['snapshot_id']], 300);
            }

            throw ProcessFailed::producer('dump the database', ProcessOutcome::from($producer, $target->credentials->secrets()));
        }

        if ($outcome->exitCode !== 0 || $summary === null) {
            throw ProcessFailed::restic('store the snapshot', $outcome);
        }

        return new BackupOutcome($summary['snapshot_id'], $bytes, $summary['data_added']);
    }

    /**
     * Backs up a small in-memory file (the sealed key bundle).
     *
     * @param  list<string>  $tags
     *
     * @throws ProcessFailed
     */
    public function backupBytes(ResticTarget $target, string $contents, string $filename, array $tags): BackupOutcome
    {
        $args = ['backup', '--stdin', '--stdin-filename', $filename, '--host', 'launchroom', '--json'];

        foreach ($tags as $tag) {
            $args[] = '--tag';
            $args[] = $tag;
        }

        $restic = $this->process($target, $args, 600);
        $restic->setInput($contents);
        $restic->run();

        $outcome = ProcessOutcome::from($restic, $target->credentials->secrets());
        $summary = $this->summary($outcome->stdout);

        if ($outcome->exitCode !== 0 || $summary === null) {
            throw ProcessFailed::restic('store the snapshot', $outcome);
        }

        return new BackupOutcome($summary['snapshot_id'], strlen($contents), $summary['data_added']);
    }

    /**
     * Snapshots carrying all $tags, newest first.
     *
     * @param  list<string>  $tags
     * @return list<array{id: string, time: string, tags: list<string>, paths: list<string>}>
     *
     * @throws ProcessFailed
     */
    public function snapshots(ResticTarget $target, array $tags): array
    {
        $args = ['snapshots', '--json'];

        if ($tags !== []) {
            $args[] = '--tag';
            $args[] = implode(',', $tags);
        }

        $outcome = $this->run($target, $args, 300);

        if ($outcome->exitCode !== 0) {
            throw ProcessFailed::restic('list snapshots', $outcome);
        }

        try {
            $decoded = json_decode($outcome->stdout, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ProcessFailed::message('restic returned an unreadable snapshot list.');
        }

        $snapshots = [];

        foreach (is_array($decoded) ? $decoded : [] as $snapshot) {
            if (! is_array($snapshot) || ! is_string($snapshot['id'] ?? null) || ! is_string($snapshot['time'] ?? null)) {
                continue;
            }

            $snapshots[] = [
                'id' => $snapshot['id'],
                'time' => $snapshot['time'],
                'tags' => array_values(array_filter(is_array($snapshot['tags'] ?? null) ? $snapshot['tags'] : [], is_string(...))),
                'paths' => array_values(array_filter(is_array($snapshot['paths'] ?? null) ? $snapshot['paths'] : [], is_string(...))),
            ];
        }

        usort($snapshots, static fn (array $a, array $b): int => strcmp($b['time'], $a['time']));

        return $snapshots;
    }

    /**
     * `restic dump <snapshot> <path>` into memory (small files only).
     *
     * @throws ProcessFailed
     */
    public function dumpToString(ResticTarget $target, string $snapshotId, string $path, int $maxBytes = 1024 * 1024): string
    {
        $outcome = $this->run($target, ['dump', $snapshotId, $path], 300);

        if ($outcome->exitCode !== 0) {
            throw ProcessFailed::restic('read the snapshot', $outcome);
        }

        if (strlen($outcome->stdout) > $maxBytes) {
            throw ProcessFailed::message('The file in the snapshot is unexpectedly large.');
        }

        return $outcome->stdout;
    }

    /**
     * Streams `restic dump <snapshot> <path>` into $consumer's stdin and
     * checks both exit codes.
     *
     * @throws ProcessFailed
     */
    public function dumpIntoProcess(ResticTarget $target, string $snapshotId, string $path, Process $consumer, int $timeout): int
    {
        $restic = $this->process($target, ['dump', $snapshotId, $path], $timeout);
        $restic->start();

        $bytes = 0;
        // The consumer pulls chunks as its stdin becomes writable: nothing is
        // buffered beyond one chunk, nothing is staged on disk.
        $consumer->setInput((function () use ($restic, &$bytes): \Generator {
            foreach ($restic->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
                $bytes += strlen($chunk);

                yield $chunk;
            }
        })());
        $consumer->setTimeout($timeout);
        $consumer->run();
        $restic->wait();

        if (! $restic->isSuccessful()) {
            throw ProcessFailed::restic('read the snapshot', ProcessOutcome::from($restic, $target->credentials->secrets()));
        }

        if (! $consumer->isSuccessful()) {
            throw ProcessFailed::producer('restore the dump', ProcessOutcome::from($consumer, $target->credentials->secrets()));
        }

        return $bytes;
    }

    /**
     * @param  list<string>  $args
     */
    private function process(ResticTarget $target, array $args, ?int $timeoutSeconds): Process
    {
        if (! is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0700, true);
        }

        $env = [
            ...array_fill_keys(self::SCRUBBED_ENV, false),
            ...$target->credentials->resticEnv($target->region),
            'RESTIC_REPOSITORY' => $target->repository,
            'RESTIC_CACHE_DIR' => $this->cacheDir,
            'HOME' => $this->cacheDir,
            'TMPDIR' => sys_get_temp_dir(),
        ];

        $process = new Process([$this->binary, ...$args], null, $env, null, $timeoutSeconds ?? $this->timeoutSeconds);
        $process->setIdleTimeout(null);

        return $process;
    }

    /**
     * The `summary` message of `restic backup --json`.
     *
     * @return array{snapshot_id: string, data_added: int}|null
     */
    private function summary(#[SensitiveParameter] string $stdout): ?array
    {
        foreach (array_reverse(explode("\n", trim($stdout))) as $line) {
            try {
                $message = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($message) && ($message['message_type'] ?? null) === 'summary' && is_string($message['snapshot_id'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/D', $message['snapshot_id']) === 1) {
                return [
                    'snapshot_id' => $message['snapshot_id'],
                    'data_added' => is_int($message['data_added'] ?? null) ? $message['data_added'] : 0,
                ];
            }
        }

        return null;
    }
}
