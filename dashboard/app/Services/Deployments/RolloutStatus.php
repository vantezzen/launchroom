<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Data\Deployments\WorkloadIssueData;
use App\Data\Services\HealthcheckSettings;
use App\Enums\DeploymentFailureClass;
use App\Enums\Mesh\MeshPath;
use App\Enums\PortHint;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Models\Placement;
use App\Models\Server;
use App\Support\Deployments\LifecycleLines;
use App\Support\Deployments\PullProgressMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What a rollout is doing right now, and why it failed, in words (D68,
 * docs/04 §8.1). The Roll out and Health check steps show the current
 * sub-step while they run ("Waiting for server-2 to pick up the change",
 * "Downloading image (412 MB of 690 MB)", "Waiting for GET / on :8000 to
 * answer (attempt 3: connection refused)"), and a timeout or a fail-fast
 * failure names the sub-step that didn't finish, with the container's exit
 * code and last lines when there are some.
 */
final readonly class RolloutStatus
{
    /** Lines of the container's last output quoted in a failure. */
    private const int OUTPUT_LINES = 12;

    /**
     * @param  Collection<int, Placement>  $placements  the deployment's placements
     * @param  array<int|string, int>  $versions  server id => the NodeState version the rollout rendered
     */
    public function __construct(
        private Collection $placements,
        private array $versions,
        private ?int $port,
        private ?HealthcheckSettings $healthcheck,
        /** The deployment's image: built by launchroom (`…/apps/…`) or an upstream one through the mirror. */
        private ?string $image = null,
    ) {}

    /**
     * A download that moved no bytes for $seconds (D68): the connection to
     * the registry stalled. Names the server and the path the image takes.
     */
    public function stalledPull(int $seconds): ?string
    {
        $built = $this->image !== null && str_contains($this->image, '/apps/');

        foreach ($this->placements as $placement) {
            $pull = $placement->pull;

            if ($placement->phase !== WorkloadPhase::Pulling || $pull === null || $pull->updatedAt === null) {
                continue;
            }

            $updated = CarbonImmutable::parse($pull->updatedAt);

            // Unpacking and an upstream image's mirror sync have no byte progress.
            $watched = $pull->stage === 'downloading' || ($pull->stage === 'mirroring' && $built);

            if ($watched && $updated->addSeconds($seconds)->isPast()) {
                $server = $this->servers()->get($placement->server_id);
                $name = $server instanceof Server ? $server->name : 'The server';
                $got = $pull->total > 0 ? ' ('.PullProgressMessage::bytes($pull->downloaded).' of '.PullProgressMessage::bytes($pull->total).' so far)' : '';

                return "{$name} couldn't download the image {$this->path($server)}: no data for ".self::duration($seconds)."{$got}. Check the server's private network connection on its page, then deploy again.";
            }
        }

        return null;
    }

    /**
     * When an upstream image's mirror sync was last seen (it extends the
     * Roll out step's timeout; launchroomd bounds it at 45 minutes).
     */
    public function mirroringSince(): ?CarbonImmutable
    {
        if ($this->image === null || str_contains($this->image, '/apps/')) {
            return null;
        }

        $latest = null;

        foreach ($this->placements as $placement) {
            if ($placement->phase === WorkloadPhase::Pulling && $placement->pull?->stage === 'mirroring' && $placement->observed_at !== null) {
                $at = CarbonImmutable::instance($placement->observed_at);
                $latest = ! $latest instanceof CarbonImmutable || $at->greaterThan($latest) ? $at : $latest;
            }
        }

        return $latest;
    }

    private function path(?Server $server): string
    {
        if (! $server instanceof Server || $server->is_control_plane) {
            return 'from its registry';
        }

        $main = Server::query()->where('team_id', $server->team_id)->controlPlane()->latest('id')->first();
        $relayed = $main instanceof Server && $server->mesh_status?->peer($main->mesh_ip)?->connection === MeshPath::Relay;

        return 'from '.($main instanceof Server ? $main->name : 'the main server')."'s registry over the private network".($relayed ? ' (the connection is relayed, not direct)' : '');
    }

    public function rollout(): ?string
    {
        $servers = $this->servers();

        foreach ($this->placements as $placement) {
            $server = $servers->get($placement->server_id);

            if (! $server instanceof Server || $placement->phase->isObserved()) {
                continue;
            }

            if ($server->status !== ServerStatus::Online) {
                return "Waiting for {$server->name}, which is {$this->serverState($server)}";
            }

            if ($server->observed_version < ($this->versions[$server->id] ?? $this->versions[(string) $server->id] ?? 0)) {
                return "Waiting for {$server->name} to pick up the change";
            }
        }

        $pull = PullProgressMessage::for($this->placements);

        if ($pull !== null) {
            return $pull;
        }

        foreach ($this->placements as $placement) {
            if (! $placement->phase->isObserved()) {
                $server = $servers->get($placement->server_id);
                $name = $server instanceof Server ? $server->name : 'the server';

                return $placement->message !== null && $placement->message !== ''
                    ? "{$name}: ".$placement->message
                    : "Waiting for {$name} to create the container";
            }
        }

        return null;
    }

    public function rolloutTimeout(int $seconds): string
    {
        $servers = $this->servers();
        $within = self::duration($seconds);

        foreach ($this->placements as $placement) {
            $server = $servers->get($placement->server_id);

            if (! $server instanceof Server || $placement->phase->isObserved()) {
                continue;
            }

            if ($server->status !== ServerStatus::Online) {
                return "{$server->name} is {$this->serverState($server)}, so the new version couldn't start there within {$within}.";
            }

            if ($server->observed_version < ($this->versions[$server->id] ?? $this->versions[(string) $server->id] ?? 0)) {
                return "{$server->name} didn't pick up the change within {$within}. It still runs an earlier desired state (it last reported {$this->ago($server->last_seen_at)}); check the server's page for a refused state or a long image download.";
            }

            if ($placement->phase === WorkloadPhase::Pulling) {
                $pull = $placement->pull;
                $got = $pull !== null && $pull->total > 0
                    ? ' ('.PullProgressMessage::bytes($pull->downloaded).' of '.PullProgressMessage::bytes($pull->total).')'
                    : '';

                return "{$server->name} was still downloading the image {$this->path($server)} after {$within}{$got}. Raise the Roll out timeout in Settings → Deploy for a large image, or check the server's private network connection.";
            }

            return "The container on {$server->name} wasn't created within {$within}".($placement->message !== null && $placement->message !== '' ? ": {$placement->message}" : '.');
        }

        return "The new version didn't start within {$within}.";
    }

    public function health(): ?string
    {
        $probe = LifecycleLines::probeLabel($this->port, $this->healthcheck);
        $several = $this->placements->count() > 1;

        foreach ($this->placements->sortBy('slot') as $placement) {
            if ($placement->phase === WorkloadPhase::Healthy) {
                continue;
            }

            $prefix = $several ? 'Replica '.($placement->slot + 1).': ' : '';
            $restarts = $placement->restart_count;

            if ($placement->phase === WorkloadPhase::Pulling) {
                return $prefix.(PullProgressMessage::for([$placement]) ?? 'Downloading image');
            }

            if ($placement->phase === WorkloadPhase::Unhealthy && $restarts > 0) {
                return $prefix."The container keeps restarting ({$restarts} so far)";
            }

            if ($probe === null) {
                return $prefix.'Waiting until the container has been running for 10 seconds';
            }

            $attempts = $placement->probe->attempts ?? 0;
            $error = $placement->probe?->lastError;

            return $prefix."Waiting for {$probe} to answer".match (true) {
                $attempts > 0 && $error !== null => " (attempt {$attempts}: {$error})",
                $attempts > 0 => " (attempt {$attempts})",
                default => '',
            };
        }

        return null;
    }

    public function healthTimeout(int $seconds, ?Placement $unhealthy): string
    {
        $within = self::duration($seconds);

        if (! $unhealthy instanceof Placement) {
            return 'The new version is healthy, but the servers it depends on did not admit it on the mesh in time (their firewall update was not applied).';
        }

        $probe = LifecycleLines::probeLabel($this->port, $this->healthcheck);
        $attempts = $unhealthy->probe->attempts ?? 0;
        $error = $unhealthy->probe?->lastError;

        $message = $probe === null
            ? "The container didn't keep running within {$within}."
            : "The container never became healthy within {$within}: {$probe} ".($error === null ? "didn't answer" : "kept failing ({$error}").($attempts > 0 ? ($error === null ? " after {$attempts} attempts" : ", {$attempts} attempts)") : ($error === null ? '' : ')')).'.';

        $hint = $this->portHint($unhealthy);

        if ($hint !== null) {
            $message .= ' '.$hint['hint']->fact($hint['port'], $hint['listening']);
        }

        return $message.$this->output($unhealthy);
    }

    /**
     * launchroomd's port hint for a replica: from its fail-fast issue, else
     * from its probe (report.json WorkloadStatus.portHint).
     *
     * @return array{hint: PortHint, listening: list<int>, port: int|null}|null
     */
    public function portHint(?Placement $placement): ?array
    {
        if (! $placement instanceof Placement) {
            return null;
        }

        $issue = $placement->issue;
        $issueHint = $issue?->kind->portHint();

        if ($issue !== null && $issueHint instanceof PortHint) {
            return [
                'hint' => $issueHint,
                'listening' => $issue->listening,
                'port' => $issue->port ?? $this->port,
            ];
        }

        $hint = $placement->probe?->portHint;

        return $hint === null ? null : [
            'hint' => $hint,
            'listening' => $placement->probe->listening ?? [],
            'port' => $this->port,
        ];
    }

    /**
     * The port hint as a failed step's meta, for FailureCatalogue.
     *
     * @return array{portHint?: string, listening?: list<int>, port?: int}
     */
    public function failureMeta(?Placement $placement): array
    {
        $hint = $this->portHint($placement);

        return $hint === null ? [] : array_filter([
            'portHint' => $hint['hint']->value,
            'listening' => $hint['listening'],
            'port' => $hint['port'],
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array{DeploymentFailureClass, string}|null
     */
    public function issue(Placement $placement): ?array
    {
        $issue = $placement->issue;

        if ($issue === null) {
            return null;
        }

        $hint = $issue->kind->portHint();

        if (! $hint instanceof PortHint) {
            return [DeploymentFailureClass::RuntimeError, $this->crashLoop($placement, $issue)];
        }

        $expected = $issue->port ?? $this->port;
        $fact = 'The container never became healthy. '.$hint->fact($expected, $issue->listening);

        return [DeploymentFailureClass::PortMismatch, $hint === PortHint::LoopbackOnly
            ? $fact." launchroom expects it on 0.0.0.0:{$expected} (\$PORT). Make it bind to 0.0.0.0, for example with --host 0.0.0.0."
            : $fact.' Set the service port to '.($issue->listening[0] ?? $expected).' in Settings → Deploy, or make the app listen on $PORT.'];
    }

    public function stopped(Placement $placement): string
    {
        $exit = $placement->last_exit;

        $server = $this->servers()->get($placement->server_id);
        $name = $server instanceof Server ? $server->name : 'The server';

        $message = match (true) {
            // launchroomd couldn't create or start it (a policy refusal, a
            // create error, a pull failure): its reason, as it reported it.
            $placement->phase === WorkloadPhase::Failed && $placement->message !== null && $placement->message !== '' => "{$name} couldn't run the container: {$placement->message}",
            $placement->phase === WorkloadPhase::Removed => "launchroom removed the container before it became healthy{$this->removedBecause($placement)}.",
            $exit !== null && $exit->oomKilled => 'Out of memory: the container was killed before it became healthy. Raise its memory limit in Settings → Scaling.',
            $exit !== null && $exit->code !== null => "The container exited with code {$exit->code} before it became healthy.",
            $placement->message !== null && $placement->message !== '' => $placement->message,
            default => "The container {$placement->phase->value} before it became healthy.",
        };

        return $message.$this->output($placement);
    }

    private function crashLoop(Placement $placement, WorkloadIssueData $issue): string
    {
        $restarts = $issue->restarts ?? $placement->restart_count;
        $code = $issue->exitCode;

        $message = $issue->oomKilled
            ? "The container keeps running out of memory: it was killed {$restarts} times. Raise its memory limit in Settings → Scaling."
            : 'The container keeps crashing: it exited'.($code === null ? '' : " with code {$code}")." and was restarted {$restarts} times.";

        return $message.$this->output($placement);
    }

    private function removedBecause(Placement $placement): string
    {
        return $placement->message !== null && $placement->message !== '' ? " ({$placement->message})" : '';
    }

    private function output(Placement $placement): string
    {
        $exit = $placement->last_exit;
        $lines = $exit !== null ? array_slice($exit->output, -self::OUTPUT_LINES) : [];

        if ($lines === []) {
            return '';
        }

        return "\n\nLast output:\n".implode("\n", array_map(static fn (array $line): string => mb_substr($line['text'], 0, 300), $lines));
    }

    /**
     * @return Collection<int, Server>
     */
    private function servers(): Collection
    {
        return Server::query()->whereKey($this->placements->pluck('server_id')->unique()->values()->all())->get()->keyBy('id');
    }

    private function serverState(Server $server): string
    {
        return ($server->status === ServerStatus::Offline ? 'offline' : 'not reachable').($server->last_seen_at !== null ? ' (last seen '.$this->ago($server->last_seen_at).')' : '');
    }

    private function ago(?CarbonInterface $at): string
    {
        return $at instanceof CarbonInterface ? CarbonImmutable::instance($at)->diffForHumans(['short' => false, 'parts' => 1]) : 'never';
    }

    public static function duration(int $seconds): string
    {
        return $seconds >= 120 && $seconds % 60 === 0 ? intdiv($seconds, 60).' minutes' : "{$seconds} seconds";
    }
}
