<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Report `workloads[].issue` (report.json WorkloadIssue, D68): a starting
 * container that is clearly misconfigured. `crash_loop` (Docker restarted it
 * at least 3 times), `loopback_only` (listens on 127.0.0.1/::1 only),
 * `wrong_port` (listens on other ports). The dashboard fails an in-flight
 * deployment on it right away (AdvanceDeployment).
 */
final class WorkloadIssue extends Data
{
    /**
     * @param  list<int>|null|Optional  $listening
     */
    public function __construct(
        #[In(['crash_loop', 'loopback_only', 'wrong_port'])]
        public string $kind,
        #[Regex(Report::TIME_PATTERN)]
        public string $since,
        #[Min(1), Max(65535)]
        public int|null|Optional $port = new Optional,
        public array|null|Optional $listening = new Optional,
        public int|null|Optional $exitCode = new Optional,
        public bool|Optional $oomKilled = new Optional,
        #[Min(0)]
        public int|Optional $restarts = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['listening' => ['nullable', 'array', 'max:32'], 'listening.*' => ['integer', 'min:1', 'max:65535']];
    }

    /**
     * Stored on the placement (`placements.issue`).
     *
     * @return array{kind: string, since: string, port?: int, listening?: list<int>, exitCode?: int, oomKilled?: bool, restarts?: int}
     */
    public function toStored(): array
    {
        /** @var list<int> $listening */
        $listening = is_array($this->listening) ? array_values(array_filter($this->listening, is_int(...))) : [];

        return [
            'kind' => $this->kind,
            'since' => $this->since,
            ...(is_int($this->port) ? ['port' => $this->port] : []),
            ...($listening === [] ? [] : ['listening' => $listening]),
            ...(is_int($this->exitCode) ? ['exitCode' => $this->exitCode] : []),
            ...($this->oomKilled === true ? ['oomKilled' => true] : []),
            ...(is_int($this->restarts) ? ['restarts' => $this->restarts] : []),
        ];
    }
}
