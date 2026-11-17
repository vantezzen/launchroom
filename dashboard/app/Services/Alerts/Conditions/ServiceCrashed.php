<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertKind;
use App\Enums\Alerts\AlertRule;
use App\Enums\EnvironmentKind;
use App\Models\ServiceInstance;
use App\Support\Deployments\ExitMeaning;
use App\Support\Links;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `service_crashed` (opt-in, G7): a replica of a live deployment exited
 * with an error that launchroom didn't cause (`service_crashes`, recorded
 * from Report.events by RecordWorkloadRuns). It fires on the first crash and
 * stays open while the service crashed within the last WINDOW_MINUTES, so a
 * service that keeps crashing notifies at most once an hour. Previews never
 * alert; a stopped or removed service doesn't either.
 */
final readonly class ServiceCrashed extends AlertCondition
{
    public const int WINDOW_MINUTES = 60;

    public function rule(): AlertRule
    {
        return AlertRule::ServiceCrashed;
    }

    public function resolvedReason(): string
    {
        return 'It ran for an hour without crashing.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $rows = DB::table('service_crashes as c')
            ->join('service_instances as si', 'si.id', '=', 'c.service_instance_id')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->whereNull('si.removed_at')
            ->whereNull('si.stopped_at')
            ->where('e.kind', '!=', EnvironmentKind::Preview->value)
            ->where('c.at', '>=', $now->subMinutes(self::WINDOW_MINUTES))
            ->orderBy('c.at')
            ->get(['c.service_instance_id', 'c.at', 'c.exit_code', 'c.signal', 'c.oom_killed']);

        /** @var array<int, array{count: int, code: int|null, signal: int|null, oom: bool}> $crashes */
        $crashes = [];

        foreach ($rows as $row) {
            $id = Cast::id($row->service_instance_id ?? null);
            $count = ($crashes[$id]['count'] ?? 0) + 1;
            $crashes[$id] = [
                'count' => $count,
                'code' => is_numeric($row->exit_code ?? null) ? (int) $row->exit_code : null,
                'signal' => is_numeric($row->signal ?? null) ? (int) $row->signal : null,
                'oom' => (bool) ($row->oom_killed ?? false),
            ];
        }

        $out = [];
        $instances = ServiceInstance::query()->whereKey(array_keys($crashes))->with(['service', 'environment.project'])->get();

        foreach ($instances as $instance) {
            $crash = $crashes[$instance->id];
            $out[$instance->team_id.'|service:'.$instance->public_id] = function () use ($instance, $crash, $now): AlertSignal {
                $name = $instance->service->name;
                $environment = $instance->environment;
                $what = ExitMeaning::summary($crash['code'], $crash['oom'], $crash['signal']);
                $advice = ExitMeaning::advice($crash['code'], $crash['oom'], $crash['signal']);

                return new AlertSignal(
                    team: $this->team($instance->team_id),
                    rule: AlertRule::ServiceCrashed,
                    subject: 'service:'.$instance->public_id,
                    title: "{$name} crashed in {$environment->name}",
                    summary: mb_substr(trim("{$what} launchroom started it again. ".($advice ?? 'Its logs usually say why.')), 0, 1000),
                    href: Links::serviceLogs($instance, 'level:error', $now->subMinutes(self::WINDOW_MINUTES), $now),
                    project: $environment->project,
                    environment: $environment,
                    serviceInstance: $instance,
                    facts: ['Crashes' => $crash['count'].' in the last hour'],
                    kind: AlertKind::Restarting,
                );
            };
        }

        return $out;
    }
}
