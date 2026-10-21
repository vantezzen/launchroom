<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Enums\LogSourceKind;
use App\Models\DaemonTask;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\LogChunk;
use App\Support\Dockerfiles\RepositoryContext;

/**
 * The bookkeeping of a generation's attempts (docs/20 §5): one entry per
 * draft with its Dockerfile, lint, build and start outcome; repairs while
 * attempts remain, then a plain-words failure. Callers hold the row lock.
 */
final class DockerfileAttempts
{
    /**
     * Merges $patch into the newest attempt.
     *
     * @param  array<string, mixed>  $patch
     */
    public function record(DockerfileGeneration $generation, array $patch): void
    {
        $attempts = $generation->attempts ?? [];

        if ($attempts === []) {
            $attempts[] = ['n' => max(1, $generation->attempt)];
        }

        $last = count($attempts) - 1;
        $attempts[$last] = [...$attempts[$last], ...$patch];
        $generation->attempts = $attempts;
    }

    /**
     * Records why the newest attempt failed and goes back to drafting when
     * attempts remain.
     *
     * @return bool whether a repair draft should start
     */
    public function repairOrFail(DockerfileGeneration $generation, string $outcome, string $message, string $log = ''): bool
    {
        $this->record($generation, ['outcome' => $outcome, 'message' => $message, 'log' => $log === '' ? null : $log]);

        if ($generation->attempt < $generation->max_attempts) {
            $generation->transitionTo(DockerfileGenerationStatus::Drafting);

            return true;
        }

        $this->fail($generation, match ($outcome) {
            'lint' => "launchroom couldn't get a Dockerfile that passes its checks after {$generation->attempt} tries.",
            'build' => "The Dockerfile still didn't build after {$generation->attempt} tries.",
            'start' => "The image built, but the app still didn't start after {$generation->attempt} tries.",
            default => $message,
        }.($outcome === 'model' ? '' : ' Last problem: '.mb_substr($message, 0, 400)));

        return false;
    }

    public function fail(DockerfileGeneration $generation, string $message): void
    {
        $generation->forceFill(['error' => mb_substr($message, 0, 2000)]);
        $generation->transitionTo(DockerfileGenerationStatus::Failed);
    }

    /**
     * The last lines of a task's log, masked by launchroomd and scrubbed again.
     */
    public function logTail(DaemonTask $task): string
    {
        $limit = config()->integer('launchroom.dockerfile_generation.log_tail_lines');
        $chunks = LogChunk::query()
            ->where('team_id', $task->team_id)
            ->where('source_kind', LogSourceKind::Task->value)
            ->where('source_id', $task->public_id)
            ->orderByDesc('seq')
            ->limit(20)
            ->get(['id', 'seq', 'lines']);

        $lines = [];

        foreach ($chunks as $chunk) {
            $texts = array_map(static fn (array $line): string => $line['text'], $chunk->lines);
            $lines = [...$texts, ...$lines];

            if (count($lines) >= $limit) {
                break;
            }
        }

        $tail = implode("\n", array_slice($lines, -$limit));

        if ($task->error !== null && ! str_contains($tail, $task->error)) {
            $tail .= ($tail === '' ? '' : "\n").$task->error;
        }

        return RepositoryContext::scrub(mb_strcut($tail, max(0, strlen($tail) - 12288)));
    }

    /**
     * What the plan detected, for the prompt and the sheet.
     *
     * @param  array<mixed>  $summary
     * @return array<string, string|int|null>
     */
    public static function facts(array $summary): array
    {
        $runtime = null;

        foreach (is_array($summary['packages'] ?? null) ? $summary['packages'] : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $runtime = trim(($runtime === null ? '' : $runtime.', ').$package['name'].' '.$package['version']);
            }
        }

        $string = static fn (string $key): ?string => is_string($summary[$key] ?? null) && $summary[$key] !== '' ? mb_substr($summary[$key], 0, 512) : null;

        return [
            'provider' => $string('provider'),
            'framework' => $string('framework'),
            'packageManager' => $string('packageManager'),
            'runtime' => $runtime === null ? null : mb_substr($runtime, 0, 512),
            'installCommand' => $string('installCommand'),
            'buildCommand' => $string('buildCommand'),
            'startCommand' => $string('startCommand'),
            'port' => is_int($summary['port'] ?? null) ? $summary['port'] : null,
        ];
    }
}
