<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Data\Services\HealthcheckSettings;
use App\Enums\DeploymentFailureClass;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Models\Placement;
use App\Models\Server;
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

            if ($placement->phase !== WorkloadPhase::Pulling || $pull === null || ! isset($pull['updatedAt'])) {
                continue;
            }

            $updated = CarbonImmutable::parse($pull['updatedAt']);

            // Unpacking and an upstream image's mirror sync have no byte progress.
            $watched = $pull['stage'] === 'downloading' || ($pull['stage'] === 'mirroring' && $built);

            if ($watched && $updated->addSeconds($seconds)->isPast()) {
                $server = $this->servers()->get($placement->server_id);
                $name = $server instanceof Server ? $server->name : 'The server';
                $got = $pull['total'] > 0 ? ' ('.PullProgressMessage::bytes($pull['downloaded']).' of '.PullProgressMessage::bytes($pull['total']).' so far)' : '';

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
            if ($placement->phase === WorkloadPhase::Pulling && ($placement->pull['stage'] ?? null) === 'mirroring' && $placement->observed_at !== null) {
                $at = CarbonImmutable::instance($placement->observed_at);
                $latest = ! $latest instanceof CarbonImmutable || $at->greaterThan($latest) ? $at : $latest;
            }
        }

        return $latest;
    }

    /**
     * Where a server downloads images from, in words: "from the main
     * server's registry over the private network (the connection is
     * relayed)", or "from this server's registry".
     */
    private function path(?Server $server): string
    {
        if (! $server instanceof Server || $server->is_control_plane) {
            return 'from its registry';
        }

        $main = Server::query()->where('team_id', $server->team_id)->where('is_control_plane', true)->first();
        $relayed = false;

        foreach ($server->mesh_status['peers'] ?? [] as $peer) {
            if ($main instanceof Server && $peer['ipv4'] === $main->mesh_ip) {
                $relayed = $peer['connection'] === 'relay';
            }
        }

        return 'from '.($main instanceof Server ? $main->name : 'the main server')."'s registry over the private network".($relayed ? ' (the connection is relayed, not direct)' : '');
    }

    /**
     * The Roll out step's current sub-step (null: nothing to say).
     */
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

    /**
     * Why the Roll out step didn't finish in time.
     */
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
                $pull = is_array($placement->pull) ? $placement->pull : null;
                $got = $pull !== null && $pull['total'] > 0
                    ? ' ('.PullProgressMessage::bytes($pull['downloaded']).' of '.PullProgressMessage::bytes($pull['total']).')'
                    : '';

                return "{$server->name} was still downloading the image {$this->path($server)} after {$within}{$got}. Raise the Roll out timeout in Settings → Deploy for a large image, or check the server's private network connection.";
            }

            return "The container on {$server->name} wasn't created within {$within}".($placement->message !== null && $placement->message !== '' ? ": {$placement->message}" : '.');
        }

        return "The new version didn't start within {$within}.";
    }

    /**
     * The Health check step's current sub-step.
     */
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

            $attempts = is_int($placement->probe['attempts'] ?? null) ? $placement->probe['attempts'] : 0;
            $error = is_string($placement->probe['lastError'] ?? null) ? $placement->probe['lastError'] : null;

            return $prefix."Waiting for {$probe} to answer".match (true) {
                $attempts > 0 && $error !== null => " (attempt {$attempts}: {$error})",
                $attempts > 0 => " (attempt {$attempts})",
                default => '',
            };
        }

        return null;
    }

    /**
     * Why the Health check step didn't pass in time.
     */
    public function healthTimeout(int $seconds, ?Placement $unhealthy): string
    {
        $within = self::duration($seconds);

        if (! $unhealthy instanceof Placement) {
            return 'The new version is healthy, but the servers it depends on did not admit it on the mesh in time (their firewall update was not applied).';
        }

        $probe = LifecycleLines::probeLabel($this->port, $this->healthcheck);
        $attempts = is_int($unhealthy->probe['attempts'] ?? null) ? $unhealthy->probe['attempts'] : 0;
        $error = is_string($unhealthy->probe['lastError'] ?? null) ? $unhealthy->probe['lastError'] : null;

        $message = $probe === null
            ? "The container didn't keep running within {$within}."
            : "The container never became healthy within {$within}: {$probe} ".($error === null ? "didn't answer" : "kept failing ({$error}").($attempts > 0 ? ($error === null ? " after {$attempts} attempts" : ", {$attempts} attempts)") : ($error === null ? '' : ')')).'.';

        if ($unhealthy->message !== null && str_contains($unhealthy->message, 'the app ')) {
            // launchroomd's port hint ("the app does not listen on any TCP port (expected 8000)").
            $hint = trim((string) strstr($unhealthy->message, 'the app '));
            $message .= ' '.ucfirst($hint).'.';
        }

        return $message.$this->output($unhealthy);
    }

    /**
     * A fail-fast failure from launchroomd's issue on a placement.
     *
     * @return array{DeploymentFailureClass, string}|null
     */
    public function issue(Placement $placement): ?array
    {
        $issue = $placement->issue;

        if (! is_array($issue)) {
            return null;
        }

        $expected = is_int($issue['port'] ?? null) ? $issue['port'] : $this->port;
        $listening = array_values(array_filter($issue['listening'] ?? [], is_int(...)));

        return match ($issue['kind']) {
            'crash_loop' => [DeploymentFailureClass::RuntimeError, $this->crashLoop($placement, $issue)],
            'loopback_only' => [DeploymentFailureClass::PortMismatch,
                "The container never became healthy: it listens on 127.0.0.1:{$expected}, so nothing outside the container reaches it. launchroom expects it on 0.0.0.0:{$expected} (\$PORT). Make it bind to 0.0.0.0, for example with --host 0.0.0.0."],
            'wrong_port' => [DeploymentFailureClass::PortMismatch,
                'The app listens on '.$this->list($listening).", not {$expected}, where launchroom sends traffic. Set the service port to ".($listening[0] ?? $expected).' in Settings → Deploy, or make the app listen on $PORT.'],
            default => null,
        };
    }

    /**
     * A placement that stopped before it became healthy.
     */
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
            is_array($exit) && $exit['oomKilled'] => 'Out of memory: the container was killed before it became healthy. Raise its memory limit in Settings → Scaling.',
            is_array($exit) && is_int($exit['code']) => "The container exited with code {$exit['code']} before it became healthy.",
            $placement->message !== null && $placement->message !== '' => $placement->message,
            default => "The container {$placement->phase->value} before it became healthy.",
        };

        return $message.$this->output($placement);
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function crashLoop(Placement $placement, array $issue): string
    {
        $restarts = is_int($issue['restarts'] ?? null) ? $issue['restarts'] : $placement->restart_count;
        $code = is_int($issue['exitCode'] ?? null) ? $issue['exitCode'] : null;

        $message = ($issue['oomKilled'] ?? false) === true
            ? "The container keeps running out of memory: it was killed {$restarts} times. Raise its memory limit in Settings → Scaling."
            : 'The container keeps crashing: it exited'.($code === null ? '' : " with code {$code}")." and was restarted {$restarts} times.";

        return $message.$this->output($placement);
    }

    private function removedBecause(Placement $placement): string
    {
        return $placement->message !== null && $placement->message !== '' ? " ({$placement->message})" : '';
    }

    /**
     * The container's last output lines, quoted after a failure.
     */
    private function output(Placement $placement): string
    {
        $exit = $placement->last_exit;
        $lines = is_array($exit) ? array_slice($exit['output'], -self::OUTPUT_LINES) : [];

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

    /** "5 minutes", "90 seconds". */
    public static function duration(int $seconds): string
    {
        return $seconds >= 120 && $seconds % 60 === 0 ? intdiv($seconds, 60).' minutes' : "{$seconds} seconds";
    }

    /**
     * @param  list<int>  $ports
     */
    private function list(array $ports): string
    {
        return $ports === [] ? 'other ports' : implode(', ', $ports);
    }
}
