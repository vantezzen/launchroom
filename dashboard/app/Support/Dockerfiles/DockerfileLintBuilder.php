<?php

declare(strict_types=1);

namespace App\Support\Dockerfiles;

/**
 * Collects problems and references while DockerfileLinter walks the file.
 *
 * @internal
 */
final class DockerfileLintBuilder
{
    /** @var list<array{line: int, message: string}> */
    private array $problems = [];

    /** @var list<array{line: int, reference: string}> */
    private array $references = [];

    public function problem(int $line, string $message): void
    {
        if (count($this->problems) < 50) {
            $this->problems[] = ['line' => $line, 'message' => $message];
        }
    }

    public function reference(int $line, string $reference): void
    {
        $this->references[] = ['line' => $line, 'reference' => $reference];
    }

    public function result(): DockerfileLint
    {
        return new DockerfileLint($this->problems, $this->references);
    }
}
