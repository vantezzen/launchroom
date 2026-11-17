<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Data\Servers\Host\DaemonRefusalData;
use App\Data\Servers\Host\DockerReachData;
use App\Data\Servers\Host\HostStatusData;
use App\Data\Servers\Host\ReconcileStatusData;
use App\Enums\HealthCheckState;
use App\Enums\Servers\DaemonExitKind;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Something that happened to a server that its page names in a sentence
 * (G3, G4, G13): why the dashboard refuses it, launchroom can't reach
 * Docker or can't run apps, launchroomd crashed or got stuck, the machine
 * restarted. `trace` (a crash's panic trace) is only filled for people who
 * may change the server.
 */
final class ServerNoticeData extends Data
{
    /** How long a crash or restart stays on the page. */
    public const int EXIT_DAYS = 7;

    /** launchroomd checks Docker every 30 s; one failed check isn't worth a notice. */
    public const int DOCKER_GRACE_SECONDS = 60;

    public function __construct(
        public string $key,
        public HealthCheckState $state,
        public string $title,
        public string $detail,
        public ?string $at = null,
        public ?string $trace = null,
        /** A section of the server page that shows more (`disk`). */
        public ?string $anchor = null,
    ) {}

    /**
     * Most important first.
     *
     * @return list<self>
     */
    public static function forServer(Server $server, bool $admin = false): array
    {
        $now = CarbonImmutable::now();
        $notices = [];
        $rejection = $server->last_rejection;

        if ($rejection instanceof RequestRejectionData && $rejection->current($server->last_report_at)) {
            $why = $rejection->explain();
            $notices[] = new self('rejected', HealthCheckState::Critical, "The dashboard refuses {$server->name}: ".lcfirst($why['title']), $why['detail'], $rejection->at);
        }

        $host = $server->host_status;

        if ($host instanceof HostStatusData) {
            array_push($notices, ...self::docker($server, $host, $now));
            $refusal = $host->lastRefusal;

            if ($refusal instanceof DaemonRefusalData && CarbonImmutable::parse($refusal->at)->greaterThan($now->subDay()) && (! $rejection instanceof RequestRejectionData || ! $rejection->current($server->last_report_at))) {
                $notices[] = new self('refused', HealthCheckState::Ok, 'The dashboard refused it earlier', self::refusal($refusal->status, $refusal->clockSkewMs), $refusal->at);
            }
        }

        $exit = $server->last_daemon_exit;

        if ($exit instanceof DaemonExitData && CarbonImmutable::parse($exit->reportedAt)->greaterThan($now->subDays(self::EXIT_DAYS))) {
            $notices[] = self::exit($server, $exit, $admin);
        }

        return $notices;
    }

    /**
     * @return list<self>
     */
    private static function docker(Server $server, HostStatusData $host, CarbonImmutable $now): array
    {
        $out = [];
        $docker = $host->docker;

        if ($docker instanceof DockerReachData && ! $docker->reachable && CarbonImmutable::parse($docker->checkedAt)->greaterThan($now->subMinutes(10))) {
            $out[] = new self('docker', HealthCheckState::Critical, "{$server->name} can’t reach Docker",
                'launchroom can’t start, stop or check your apps on it. The ones running keep running. Check that Docker runs on the machine (`sudo systemctl status docker`).'.($docker->error !== null ? " Docker said: {$docker->error}" : ''),
                $docker->checkedAt);

            return $out;
        }

        $reconcile = $host->reconcile;

        if (! $reconcile instanceof ReconcileStatusData) {
            return $out;
        }

        if ($reconcile->has('docker_unreachable', 'guard_refused')) {
            $out[] = new self('reconcile', HealthCheckState::Critical, "{$server->name} is online but can’t run apps",
                'launchroom can’t change containers on it right now, so new versions and restarts wait. '.self::message($reconcile, 'docker_unreachable', 'guard_refused'), $reconcile->lastPassAt);
        } elseif ($reconcile->has('not_isolated')) {
            $out[] = new self('reconcile', HealthCheckState::Critical, "Apps on {$server->name} are held until its firewall works",
                'launchroom only starts apps once their networks are isolated from each other. '.self::message($reconcile, 'not_isolated'), $reconcile->lastPassAt);
        } elseif ($reconcile->has('disk_full')) {
            $out[] = new self('reconcile', HealthCheckState::Critical, "{$server->name}’s disk is full",
                'New versions can’t be started until there is free space again.', $reconcile->lastPassAt, anchor: 'disk');
        }

        return $out;
    }

    private static function message(ReconcileStatusData $reconcile, string ...$codes): string
    {
        foreach ($reconcile->errors as $error) {
            if (in_array($error->code, $codes, true) && $error->message !== null) {
                return "launchroom said: {$error->message}";
            }
        }

        return '';
    }

    private static function refusal(int $status, ?int $skewMs): string
    {
        $seconds = $skewMs !== null ? intdiv(abs($skewMs), 1000) : 0;

        return match (true) {
            $status === 401 && $seconds > 60 => "Its clock was {$seconds} seconds off. Turn on time sync on that machine (`sudo timedatectl set-ntp true`).",
            $status === 401 => 'The dashboard refused its signature or client certificate.',
            $status === 426 => 'Its launchroom version was too old for this dashboard.',
            $status === 403 => 'It was waiting for approval or had been replaced.',
            default => "The dashboard answered {$status}.",
        };
    }

    private static function exit(Server $server, DaemonExitData $exit, bool $admin): self
    {
        $at = $exit->at !== null ? CarbonImmutable::parse($exit->at)->format('j M H:i').' UTC' : null;
        $when = $at !== null ? " at {$at}" : '';

        return match ($exit->kind) {
            DaemonExitKind::Crash => new self('exit', HealthCheckState::Warning, "launchroom on {$server->name} restarted after a crash",
                "It crashed{$when} and started again. Your apps kept running.", $exit->at ?? $exit->reportedAt, $admin ? $exit->panic : null),
            DaemonExitKind::Stalled => new self('exit', HealthCheckState::Warning, "launchroom on {$server->name} got stuck and restarted itself",
                "It stopped making progress{$when}, so it restarted. Your apps kept running.", $exit->at ?? $exit->reportedAt, $admin ? $exit->panic : null),
            DaemonExitKind::Reboot => new self('exit', HealthCheckState::Ok, "{$server->name} restarted",
                'The machine started again'.($exit->bootedAt !== null ? ' at '.CarbonImmutable::parse($exit->bootedAt)->format('j M H:i').' UTC' : '').'. Apps came back with it.', $exit->bootedAt ?? $exit->reportedAt),
            DaemonExitKind::Unexpected => new self('exit', HealthCheckState::Warning, "launchroom on {$server->name} stopped unexpectedly",
                "It stopped{$when} without a crash report, for example because it was killed, ran out of memory or the machine lost power. It runs again.", $exit->at ?? $exit->reportedAt),
        };
    }
}
