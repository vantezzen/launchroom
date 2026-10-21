<?php

declare(strict_types=1);

namespace App\Support\Dockerfiles;

/**
 * The linter's verdict: problems (line + message, fed back to the model)
 * and the external image references that still need a digest.
 */
final readonly class DockerfileLint
{
    /**
     * @param  list<array{line: int, message: string}>  $problems
     * @param  list<array{line: int, reference: string}>  $references
     */
    public function __construct(
        public array $problems,
        public array $references,
    ) {}

    public function passed(): bool
    {
        return $this->problems === [];
    }

    /**
     * "line 3: …" lines for the repair prompt and the UI.
     */
    public function summary(): string
    {
        return implode("\n", array_map(static fn (array $p): string => "line {$p['line']}: {$p['message']}", $this->problems));
    }
}
