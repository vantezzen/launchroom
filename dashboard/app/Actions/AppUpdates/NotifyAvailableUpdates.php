<?php

declare(strict_types=1);

namespace App\Actions\AppUpdates;

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Data\Alerts\AlertSignal;
use App\Data\AppUpdates\UpdateCandidateData;
use App\Enums\Alerts\AlertRule;
use App\Enums\AppUpdates\UpdateCheckStatus;
use App\Enums\AppUpdates\UpdateKind;
use App\Enums\EnvironmentKind;
use App\Models\AppUpdates\AppUpdateCheck;
use App\Models\Team;
use App\Support\AppUpdates\UpdateChoice;
use App\Support\Links;
use Illuminate\Database\Eloquent\Builder;

/**
 * The `update_available` digest (docs/15 §3.4, D106): one alert per team
 * that lists every running app with news (a new version, or a new image
 * behind a moving tag), refreshed after each check. A new set of updates is
 * a new occurrence, which notifies again at most once a week; nothing left
 * to update resolves it quietly.
 */
final readonly class NotifyAvailableUpdates
{
    public function __construct(
        private RaiseAlert $raise,
        private ResolveAlert $resolve,
    ) {}

    public function handle(int $teamId): void
    {
        $team = Team::query()->find($teamId);

        if (! $team instanceof Team) {
            return;
        }

        $subject = 'updates:'.$team->public_id;
        $news = $this->news($teamId);
        $lines = array_map(static fn (array $item): string => $item['change'], $news);

        if ($lines === []) {
            $this->resolve->handle($teamId, AlertRule::UpdateAvailable, $subject, 'Every app runs its newest version.', notify: false);

            return;
        }

        $count = count($lines);
        $title = $count === 1 ? array_values($news)[0]['name'].' has an update' : "{$count} apps have updates";
        $facts = array_slice($lines, 0, 8, true);

        $this->raise->handle(new AlertSignal(
            team: $team,
            rule: AlertRule::UpdateAvailable,
            subject: $subject,
            title: mb_substr($title, 0, 200),
            summary: mb_substr($this->summary($lines), 0, 1000),
            href: Links::appUpdates(),
            facts: array_combine(array_map(static fn (string $key): string => mb_substr($key, 0, 40), array_keys($facts)), array_map(static fn (string $value): string => mb_substr($value, 0, 200), $facts)),
            occurrence: substr(hash('sha256', implode("\n", array_map(static fn (string $key, string $value): string => $key.'='.$value, array_keys($lines), $lines))), 0, 32),
        ));
    }

    /**
     * "listmonk in shop" => the service's name and "v6.2.0 → v6.3.0", one
     * per service instance.
     *
     * @return array<string, array{name: string, change: string}>
     */
    private function news(int $teamId): array
    {
        $checks = AppUpdateCheck::query()
            ->where('team_id', $teamId)
            ->where('status', UpdateCheckStatus::Available->value)
            ->whereHas('serviceInstance', static fn (Builder $query) => $query
                ->whereNull('removed_at')
                ->whereNull('stopped_at')
                ->whereHas('environment', static fn (Builder $environment) => $environment->where('kind', '!=', EnvironmentKind::Preview->value)))
            ->with(['serviceInstance.service', 'serviceInstance.environment.project'])
            ->orderBy('id')
            ->get();

        $lines = [];

        foreach ($checks as $check) {
            /** @var list<UpdateCandidateData> $candidates */
            $candidates = $check->candidates->all();
            $news = array_values(array_filter($candidates, UpdateChoice::isNews(...)));
            $best = UpdateChoice::recommended($news);

            if (! $best instanceof UpdateCandidateData) {
                continue;
            }

            $instance = $check->serviceInstance;
            $key = "{$instance->service->name} in {$instance->environment->project->name}".($instance->environment->kind === EnvironmentKind::Production ? '' : " {$instance->environment->name}");
            $running = UpdateChoice::runningVersion($check->image) ?? 'its version';
            $lines[$key] = ['name' => $instance->service->name, 'change' => $best->kind === UpdateKind::Build
                ? "a newer build of {$running}"
                : "{$running} → {$best->version}".($best->kind === UpdateKind::Major ? ' (major)' : '')];
        }

        return $lines;
    }

    /**
     * @param  array<string, string>  $lines
     */
    private function summary(array $lines): string
    {
        $parts = [];

        foreach (array_slice($lines, 0, 3, true) as $service => $change) {
            $parts[] = "{$service}: {$change}";
        }

        $more = count($lines) - count($parts);

        return implode('. ', $parts).'.'.($more > 0 ? " And {$more} more." : '').' Update from the Projects page; launchroom backs up first.';
    }
}
