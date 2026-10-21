<?php

declare(strict_types=1);

namespace App\Jobs\Dockerfiles;

use App\Actions\Dockerfiles\DraftDockerfile;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Services\Dockerfiles\DockerfileAttempts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One model call for a generation (docs/20 §5). Never retried by the queue:
 * a model call costs money, so a crash fails the attempt instead. A cache
 * lock makes a redelivered copy (the queue's retry_after is shorter than a
 * long model call) exit without calling the model again.
 */
#[Timeout(360)]
#[Tries(1)]
final class DraftDockerfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $generationId,
    ) {}

    public function handle(DraftDockerfile $draft): void
    {
        $lock = Cache::lock($this->lockKey($this->generationId), 400);

        if (! $lock->get()) {
            return;
        }

        try {
            $again = $draft->handle($this->generationId);
        } finally {
            $lock->release();
        }

        if ($again) {
            dispatch(new self($this->generationId));
        }
    }

    public function failed(?Throwable $e): void
    {
        // A copy redelivered while the first still runs must not fail it.
        if (Cache::lock($this->lockKey($this->generationId), 1)->get() === false) {
            return;
        }

        DB::transaction(function (): void {
            $generation = DockerfileGeneration::query()->whereKey($this->generationId)->lockForUpdate()->first();

            if ($generation instanceof DockerfileGeneration && $generation->status === DockerfileGenerationStatus::Drafting) {
                resolve(DockerfileAttempts::class)->fail($generation, 'Drafting the Dockerfile stopped unexpectedly. Try again.');
            }
        });
    }

    private function lockKey(int $id): string
    {
        return "launchroom:dockerfile-draft:{$id}";
    }
}
