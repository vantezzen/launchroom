<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use Spatie\LaravelData\Data;

/**
 * One attempt of a generation as the sheet lists it ("Second try: fixing
 * the build"). Log tails stay on the server; only the reason is shown.
 */
final class DockerfileAttemptData extends Data
{
    /**
     * @param  list<array{line: int, message: string}>  $lint
     */
    public function __construct(
        public int $n,
        /** null while running; model | lint | build | start | verified */
        public ?string $outcome,
        public ?string $message,
        public array $lint,
    ) {}
}
