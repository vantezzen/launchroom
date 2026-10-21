<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Logs\LogExplorerPageData;
use App\Data\Logs\LogQueryData;
use App\Models\Environment;
use App\Models\User;
use App\Support\Logs\LogLevel;
use App\Support\Logs\LogScope;

/**
 * One environment's logs (the project Logs tab, the public API): LogSearch
 * scoped to the environment, with the caller's live tails counted. Callers
 * authorize `view` on the environment.
 */
final readonly class EnvironmentLogs
{
    public function __construct(
        private LogSearch $search,
    ) {}

    public function page(Environment $environment, LogQueryData $query, User $user): LogExplorerPageData
    {
        return $this->search->page(LogScope::environment($environment), $query, LogTails::open($environment, $user)->count());
    }

    /**
     * Display heuristic only: lines carry no level, so keywords decide.
     */
    public static function level(string $text): string
    {
        return LogLevel::guess($text);
    }
}
