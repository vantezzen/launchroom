<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use Stringable;

/**
 * One problem of a rendered NodeState: the path in the Go validator's syntax
 * (`workloads[2].networks[0].network`, `networks.subnet[1]`) and, when the
 * problem belongs to one entry of a top-level list, that list and the entry's
 * index (so callers find the workload, route, network or volume without
 * parsing the path).
 */
final readonly class NodeStateProblem implements Stringable
{
    public function __construct(
        public string $path,
        public string $message,
        public ?string $section = null,
        public ?int $index = null,
    ) {}

    public function __toString(): string
    {
        return "{$this->path}: {$this->message}";
    }
}
