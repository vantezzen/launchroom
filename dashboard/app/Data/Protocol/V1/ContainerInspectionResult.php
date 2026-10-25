<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\WireErrors;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * TaskCompletion.containerInspection (docs/03 §13.2): what changed in a
 * workload container's writable layer and which processes run in it. Read
 * by hand ({@see self::fromWire()}) with launchroomd's grammars. Process
 * commands may contain secrets the masking missed: the dashboard stores the
 * whole result encrypted under the project DEK and never sends commands to
 * notifications or the API.
 */
final class ContainerInspectionResult extends Data
{
    public const string PATH = '/^\/[^\x00-\x1f\x7f]*$/D';

    public const string USER = '/^[A-Za-z0-9._+-]{1,32}$/D';

    public const array CHANGE_KINDS = ['added', 'modified', 'deleted'];

    public const array FILE_TYPES = ['file', 'dir', 'symlink', 'other'];

    public const array CHANGE_FLAGS = ['executable', 'system_path', 'sensitive_file', 'app_code', 'temp_dir', 'hidden'];

    public const array PROCESS_FLAGS = ['entry', 'shell', 'network_tool', 'miner', 'temp_executable', 'inline_code', 'added_binary'];

    /**
     * @param  list<array{path: string, kind: string, type?: string, size?: int, executable?: bool, modifiedAt?: string, flags?: list<string>}>  $changes
     * @param  list<array{prefix: string, count: int}>  $collapsed
     * @param  list<array{pid: int, ppid: int, user: string, command: string, flags?: list<string>}>  $processes
     */
    public function __construct(
        public string $workload,
        public string $containerId,
        public string $imageId,
        public bool $imageMatches,
        public string $startedAt,
        public int $restartCount,
        public ?string $user,
        public ?string $workingDir,
        public int $changesTotal,
        public array $changes,
        public array $collapsed,
        public bool $changesTruncated,
        public int $processesTotal,
        public array $processes,
        public bool $processesTruncated,
        public string $inspectedAt,
        /** The host's `ps` printed no parent PIDs (busybox): every ppid is 0, nothing can be judged by its parent. */
        public bool $parentsUnknown = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw
     *
     * @throws ValidationException
     */
    public static function fromWire(array $raw): self
    {
        $e = new WireErrors;
        $p = 'containerInspection';

        $workload = $e->string($raw['workload'] ?? null, "{$p}.workload", '/^wl_[0-9A-HJKMNP-TV-Z]{26}$/D', 29) ?? '';
        $containerId = $e->string($raw['containerId'] ?? null, "{$p}.containerId", '/^[a-f0-9]{64}$/D', 64) ?? '';
        $imageId = $e->string($raw['imageId'] ?? null, "{$p}.imageId", '/^sha256:[a-f0-9]{64}$/D', 71) ?? '';
        $imageMatches = $e->bool($raw['imageMatches'] ?? null, "{$p}.imageMatches", required: true);
        $startedAt = $e->time($raw['startedAt'] ?? null, "{$p}.startedAt") ?? '';
        $restartCount = $e->int($raw['restartCount'] ?? null, "{$p}.restartCount");
        $user = $e->string($raw['user'] ?? null, "{$p}.user", self::USER, 32, required: false);
        $workingDir = $e->string($raw['workingDir'] ?? null, "{$p}.workingDir", self::PATH, 1024, required: false);
        $inspectedAt = $e->time($raw['inspectedAt'] ?? null, "{$p}.inspectedAt") ?? '';

        $c = $e->object($raw['changes'] ?? null, "{$p}.changes");
        $total = $e->int($c['total'] ?? null, "{$p}.changes.total");
        $changes = [];
        $paths = [];
        $stats = 0;

        foreach ($e->list($c['entries'] ?? null, "{$p}.changes.entries", 2000) as $i => $entry) {
            $q = "{$p}.changes.entries.{$i}";
            $x = $e->object($entry, $q);
            $path = $e->string($x['path'] ?? null, "{$q}.path", self::PATH, 1024);
            $kind = $e->enum($x['kind'] ?? null, "{$q}.kind", self::CHANGE_KINDS);
            $type = isset($x['type']) ? $e->enum($x['type'], "{$q}.type", self::FILE_TYPES) : null;
            $size = isset($x['size']) ? $e->int($x['size'], "{$q}.size") : null;
            $executable = $e->bool($x['executable'] ?? null, "{$q}.executable");
            $modifiedAt = $e->time($x['modifiedAt'] ?? null, "{$q}.modifiedAt", required: false);
            $flags = self::flags($e, $x['flags'] ?? null, "{$q}.flags", self::CHANGE_FLAGS);

            if ($type !== null) {
                $stats++;

                if ($kind === 'deleted') {
                    $e->add("{$q}.type", 'is not valid for deleted paths');
                }
            } elseif ($size !== null || $executable || $modifiedAt !== null) {
                $e->add($q, 'size, executable and modifiedAt need a type');
            }

            if ($path === null || $kind === null) {
                continue;
            }

            if (isset($paths[$path])) {
                $e->add("{$q}.path", 'is not unique');
            }

            $paths[$path] = true;
            $changes[] = array_filter([
                'path' => $path,
                'kind' => $kind,
                'type' => $type,
                'size' => $size,
                'executable' => $executable ?: null,
                'modifiedAt' => $modifiedAt,
                'flags' => $flags === [] ? null : $flags,
            ], static fn (mixed $value): bool => $value !== null);
        }

        if ($stats > 256) {
            $e->add("{$p}.changes.entries", 'at most 256 entries may carry stat fields');
        }

        $collapsed = [];
        $counted = count($changes);

        foreach ($e->list($c['collapsed'] ?? null, "{$p}.changes.collapsed", 64, required: false) as $i => $entry) {
            $q = "{$p}.changes.collapsed.{$i}";
            $x = $e->object($entry, $q);
            $prefix = $e->string($x['prefix'] ?? null, "{$q}.prefix", self::PATH, 1024);
            $count = $e->int($x['count'] ?? null, "{$q}.count", 1);
            $counted += $count;

            if ($prefix !== null) {
                $collapsed[] = ['prefix' => $prefix, 'count' => $count];
            }
        }

        if ($counted > $total) {
            $e->add("{$p}.changes", 'entries and collapsed counts exceed total');
        }

        $pr = $e->object($raw['processes'] ?? null, "{$p}.processes");
        $processTotal = $e->int($pr['total'] ?? null, "{$p}.processes.total");
        $processes = [];
        $pids = [];

        foreach ($e->list($pr['entries'] ?? null, "{$p}.processes.entries", 256) as $i => $entry) {
            $q = "{$p}.processes.entries.{$i}";
            $x = $e->object($entry, $q);
            $pid = $e->int($x['pid'] ?? null, "{$q}.pid", 1);
            $ppid = $e->int($x['ppid'] ?? null, "{$q}.ppid");
            $processUser = $e->string($x['user'] ?? null, "{$q}.user", self::USER, 32);
            $command = $e->text($x['command'] ?? null, "{$q}.command", 512);
            $flags = self::flags($e, $x['flags'] ?? null, "{$q}.flags", self::PROCESS_FLAGS);

            if (isset($pids[$pid])) {
                $e->add("{$q}.pid", 'is not unique');
            }

            $pids[$pid] = true;

            if ($processUser !== null && $command !== null) {
                $processes[] = array_filter([
                    'pid' => $pid, 'ppid' => $ppid, 'user' => $processUser, 'command' => $command,
                    'flags' => $flags === [] ? null : $flags,
                ], static fn (mixed $value): bool => $value !== null);
            }
        }

        if (count($processes) > $processTotal) {
            $e->add("{$p}.processes", 'more entries than total');
        }

        $result = new self(
            $workload, $containerId, $imageId, $imageMatches, $startedAt, $restartCount, $user, $workingDir,
            $total, $changes, $collapsed, $e->bool($c['truncated'] ?? null, "{$p}.changes.truncated"),
            $processTotal, $processes, $e->bool($pr['truncated'] ?? null, "{$p}.processes.truncated"),
            $inspectedAt,
            $e->bool($pr['parentsUnknown'] ?? null, "{$p}.processes.parentsUnknown"),
        );

        $e->throwIfAny();

        return $result;
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function flags(WireErrors $e, mixed $raw, string $path, array $allowed): array
    {
        $flags = [];

        foreach ($e->list($raw, $path, 8, required: false) as $i => $flag) {
            $value = $e->enum($flag, "{$path}.{$i}", $allowed);

            if ($value !== null) {
                if (in_array($value, $flags, true)) {
                    $e->add("{$path}.{$i}", 'is not unique');
                }

                $flags[] = $value;
            }
        }

        return $flags;
    }

    /**
     * The exact wire shape (stored encrypted; contract tests).
     *
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        return array_filter([
            'workload' => $this->workload,
            'containerId' => $this->containerId,
            'imageId' => $this->imageId,
            'imageMatches' => $this->imageMatches,
            'startedAt' => $this->startedAt,
            'restartCount' => $this->restartCount,
            'user' => $this->user,
            'workingDir' => $this->workingDir,
            'changes' => array_filter([
                'total' => $this->changesTotal,
                'entries' => $this->changes,
                'collapsed' => $this->collapsed === [] ? null : $this->collapsed,
                'truncated' => $this->changesTruncated ?: null,
            ], static fn (mixed $value): bool => $value !== null),
            'processes' => array_filter([
                'total' => $this->processesTotal,
                'entries' => $this->processes,
                'truncated' => $this->processesTruncated ?: null,
                'parentsUnknown' => $this->parentsUnknown ?: null,
            ], static fn (mixed $value): bool => $value !== null),
            'inspectedAt' => $this->inspectedAt,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
