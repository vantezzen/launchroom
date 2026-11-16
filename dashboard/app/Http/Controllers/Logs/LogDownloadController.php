<?php

declare(strict_types=1);

namespace App\Http\Controllers\Logs;

use App\Data\Logs\LogDownloadData;
use App\Data\Logs\LogEntryData;
use App\Data\Logs\LogQueryData;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Routing\RouteScope;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Queries\LogSearch;
use App\Support\Logs\LogScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Download → All matching" in the log explorer (docs/08 §12): the lines
 * the same search would show, up to LogSearch::EXPORT_LIMIT, oldest first,
 * as plain text or NDJSON. Authorized and scoped exactly like the search
 * endpoints: an environment or a project (`view`), the team (`searchLogs`).
 * NDJSON lines are LogEntryData, the fields the explorer already shows.
 */
final class LogDownloadController extends Controller
{
    public function environment(Project $project, Environment $environment, LogQueryData $query, LogDownloadData $download, LogSearch $logs): StreamedResponse
    {
        RouteScope::environment($project, $environment);
        Gate::authorize('view', $environment);

        return $this->stream($logs->export(LogScope::environment($environment), $query), $download);
    }

    public function project(Project $project, LogQueryData $query, LogDownloadData $download, LogSearch $logs): StreamedResponse
    {
        Gate::authorize('view', $project);

        return $this->stream($logs->export(LogScope::project($project), $query), $download);
    }

    public function team(LogQueryData $query, LogDownloadData $download, LogSearch $logs, #[CurrentTeam] Team $team): StreamedResponse
    {
        Gate::authorize('searchLogs', $team);

        return $this->stream($logs->export(LogScope::team($team), $query), $download);
    }

    /**
     * @param  list<LogEntryData>  $entries
     */
    private function stream(array $entries, LogDownloadData $download): StreamedResponse
    {
        $ndjson = $download->format === 'ndjson';
        $name = 'launchroom-logs-'.CarbonImmutable::now()->utc()->format('Ymd-His').($ndjson ? '.ndjson' : '.log');

        return response()->streamDownload(static function () use ($entries, $ndjson): void {
            foreach ($entries as $entry) {
                echo $ndjson ? json_encode($entry->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n" : self::textLine($entry)."\n";
            }
        }, $name, [
            'Content-Type' => $ndjson ? 'application/x-ndjson; charset=utf-8' : 'text/plain; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** `2026-11-16T10:02:16.792Z ERROR web#2 message`, escape sequences removed. */
    public static function textLine(LogEntryData $entry): string
    {
        $source = $entry->service.($entry->replica !== null ? '#'.$entry->replica : '');
        $where = array_filter([$entry->project, $entry->environment], is_string(...));
        $message = preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $entry->message) ?? $entry->message;

        return implode(' ', array_filter([
            $entry->time,
            strtoupper($entry->level->value),
            $where === [] ? null : implode('/', $where),
            $source,
            str_replace(["\r\n", "\n", "\r"], ' ', $message),
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
