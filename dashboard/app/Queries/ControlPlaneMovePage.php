<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Moves\ControlPlaneMoveData;
use App\Data\Moves\DnsSwitchData;
use App\Data\Moves\MovePreflightCheckData;
use App\Data\Moves\MoveServerData;
use App\Data\Moves\ServerMoveData;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Backups\BackupDestination;
use App\Models\InstanceSettings;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Moves\ServerMove;
use App\Models\Registry\RegistryStorage;
use App\Models\Server;
use App\Services\Instance\DashboardOrigin;
use App\Services\Instance\RecoveryMode;
use App\Services\Moves\InstanceFreeze;
use App\Services\Moves\MovePlanner;
use App\Services\Transfers\TransferStore;
use App\Support\ByteSize;
use App\Support\Hostname;
use App\Support\InstallCommands;
use App\Support\ServerPlatform;
use Inertia\Inertia;

/**
 * Props of Settings → Move launchroom (resources/js/pages/settings/move.tsx,
 * docs/14 §13.4): the pre-flight, the open move and its steps, the plan
 * for the apps and data once the new server joined, and on the new
 * dashboard the checklist after the move.
 */
final readonly class ControlPlaneMovePage
{
    public const string CONTINUE_COMMAND = './launchroom move continue';

    public function __construct(
        private MovePlanner $planner,
        private DashboardOrigin $origin,
        private TransferStore $transfers,
        private MoveChecklist $checklist,
        private MoveDnsSwitch $dnsSwitch,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(?string $token, bool $checkDns): array
    {
        // The old dashboard keeps showing the move it handed over (its last page, with the DNS switch).
        $move = ControlPlaneMove::open()
            ?? (InstanceFreeze::movedTo() !== null ? ControlPlaneMove::query()->where('status', ControlPlaneMoveStatus::HandedOver->value)->latest('id')->first() : null)
            ?? ControlPlaneMove::query()->where('finished_at', '>', now()->subDay())->latest('id')->first();
        $source = Server::currentControlPlane();
        $serverMove = $move?->server_move_id === null ? null : ServerMove::query()->find($move->server_move_id);
        $planned = $move instanceof ControlPlaneMove && $move->status === ControlPlaneMoveStatus::Joined && $move->source instanceof Server && $move->target instanceof Server;
        $version = config()->string('launchroom.version');

        return [
            'move' => $move instanceof ControlPlaneMove ? ControlPlaneMoveData::fromMove($move) : null,
            'server' => $source instanceof Server ? MoveServerData::fromServer($source) : null,
            // Shown once, right after the move started.
            'command' => $token === null ? null : $this->command($token, $version),
            'preflight' => $this->preflight($source, $move?->status->isOpen() === true ? $move : null),
            'plan' => $planned ? $this->planner->plan($move->source, $move->target, true) : null,
            'dns' => Inertia::defer(fn (): array => $move instanceof ControlPlaneMove && $move->source instanceof Server && $move->target instanceof Server && $move->status->isOpen() && ! in_array($move->status, [ControlPlaneMoveStatus::Claimed, ControlPlaneMoveStatus::Joining], true)
                ? $this->planner->plan($move->source, $move->target, true, checkDns: true)->dns
                : []),
            'serverMove' => $serverMove instanceof ServerMove ? ServerMoveData::fromMove($serverMove) : null,
            // What would make the takeover refuse (StartHandover checks the same).
            'handoverBlockers' => $move instanceof ControlPlaneMove && in_array($move->status, [ControlPlaneMoveStatus::Moving, ControlPlaneMoveStatus::ReadyToHandOver], true) && $move->source instanceof Server
                ? ServicesOnServer::blocking($move->source)
                : [],
            // From the hand-over on: the records to point at the new server (live checks with ?check=1).
            'dnsSwitch' => Inertia::defer(fn (): ?DnsSwitchData => $move instanceof ControlPlaneMove && $move->dns_switch !== null
                ? $this->dnsSwitch->get($move->dns_switch, $move->new_url, $checkDns)
                : null, 'dns-switch'),
            // On the new server, in the install folder: continues the move from wherever it stopped.
            'continueCommand' => self::CONTINUE_COMMAND,
            'checklist' => InstanceSettings::current()->move_checklist === null ? null : $this->checklist->get($checkDns),
        ];
    }

    private function command(string $token, string $version): string
    {
        $flags = preg_match('/^\d+\.\d+\.\d+([-+][0-9A-Za-z.+-]+)?$/D', $version) === 1 ? ['--version', $version] : [];

        return InstallCommands::controlPlane('--move', $token, ...$flags);
    }

    /**
     * @return list<MovePreflightCheckData>
     */
    private function preflight(?Server $source, ?ControlPlaneMove $move): array
    {
        $checks = [];
        $url = $this->origin->canonical();
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        $ipBound = str_ends_with($host, '.sslip.io') || filter_var($host, FILTER_VALIDATE_IP) !== false;
        $others = $source instanceof Server ? Server::query()->where('team_id', $source->team_id)->whereKeyNot($source->id)->approved()->count() : 0;
        $settings = InstanceSettings::current();

        $checks[] = new MovePreflightCheckData('server', 'This server', match (true) {
            ! $source instanceof Server => 'blocker',
            $source->status !== ServerStatus::Online => 'blocker',
            default => 'ok',
        }, $source instanceof Server ? ($source->status === ServerStatus::Online ? "{$source->name} runs the dashboard and is online." : "{$source->name} is {$source->status->value}. It must be online to move. If it is gone, restore from a backup instead.") : 'No server runs the dashboard.');

        $checks[] = new MovePreflightCheckData('address', 'Dashboard address', $ipBound ? ($others > 0 ? 'warning' : 'info') : 'ok', match (true) {
            $ipBound && $others > 0 => "{$host} is tied to this server’s IP address. The new dashboard gets the new server’s address, and your other {$others} ".($others === 1 ? 'server needs' : 'servers need').' one command each to follow. A domain of your own avoids that.',
            $ipBound => "{$host} is tied to this server’s IP address, so the dashboard gets the new server’s address after the move.",
            Hostname::isLocal($host) => "{$host} only works on this machine.",
            default => "{$host} stays the address. During the move you point its DNS at the new server, and your servers switch over automatically.",
        });

        $checks[] = new MovePreflightCheckData('backups', 'Backups', 'ok', BackupDestination::query()->exists()
            ? 'Not needed for the move: data is copied directly between the servers. Your backups keep running from the new server.'
            : 'Not needed for the move: data is copied directly between the servers through this dashboard.');

        $checks[] = new MovePreflightCheckData('registry', 'App images', RegistryStorage::query()->exists() ? 'ok' : 'info', RegistryStorage::query()->exists()
            ? 'The registry keeps its images in your bucket, so the new server has them all.'
            : 'Images are not copied: running apps keep theirs, and each app is built again from its commit the next time it deploys (or rebuild them all after the move).');

        $free = $this->transfers->freeBytes();
        $checks[] = new MovePreflightCheckData('disk', 'Disk space here', $free !== null && $free < MovePlanner::MARGIN_BYTES ? 'warning' : 'ok', $free === null
            ? 'Data passes through this server’s disk one service at a time.'
            : 'Data passes through this server’s disk one service at a time; '.ByteSize::format($free).' are free.');

        if ($source instanceof Server && ($builds = $this->builds($source, $move)) instanceof MovePreflightCheckData) {
            $checks[] = $builds;
        }

        $mesh = $settings->mesh_enabled_at !== null || config()->boolean('launchroom.mesh.auto_enable');
        $checks[] = new MovePreflightCheckData('mesh', 'Private network', $mesh ? 'ok' : 'blocker', $mesh
            ? 'Servers use the private network while apps move. It turns on automatically when the new server joins.'
            : 'The private network is off and doesn’t turn on automatically. Turn it on in Servers → Private network first.');

        $checks[] = new MovePreflightCheckData('dns', 'DNS', 'info', 'Lower the TTL of your DNS records (to 60–300 seconds) a day ahead, so the switch to the new server’s address is quick.');

        if (RecoveryMode::active()) {
            $checks[] = new MovePreflightCheckData('recovery', 'Recovery mode', 'blocker', 'Finish recovery mode first.');
        }

        if ($settings->recovery_passphrase_set_at !== null) {
            $checks[] = new MovePreflightCheckData('kit', 'Recovery kit', 'info', $ipBound
                ? 'Download a new recovery kit after the move: it names the dashboard’s address.'
                : 'Your recovery kit and passphrase stay valid.');
        }

        return $checks;
    }

    /**
     * Apps built from a repository are deployed again on the new server, and
     * each deployment needs an online build server (docs/14 §13.3). Before
     * the new server joined it is checked as far as it is known: it takes
     * over the dashboard server's roles, and its architecture is known once
     * it claimed the move. Checked again when the move starts, when the code
     * is confirmed and before the apps move.
     */
    private function builds(Server $source, ?ControlPlaneMove $move): ?MovePreflightCheckData
    {
        $services = $this->planner->services($source);

        if ($this->planner->builtApps($source, $services) === 0) {
            return null;
        }

        $target = $move?->target;
        $arch = $move?->receiver?->arch;
        $refusal = $this->planner->buildBlocker(
            $source,
            $target instanceof Server ? $target : null,
            is_string($arch) ? ServerPlatform::fromArch($arch) : null,
            $source->hasRole(ServerRole::Build),
            $services,
        );

        if ($refusal !== null) {
            return new MovePreflightCheckData('builds', 'Builds', 'blocker', $refusal, route('servers.index', absolute: false), 'Open Servers');
        }

        return new MovePreflightCheckData('builds', 'Builds', 'ok', $source->hasRole(ServerRole::Build)
            ? "Your apps are deployed again on the new server. {$source->name} builds them meanwhile, and the new server takes over that role."
            : 'Your apps are deployed again on the new server, built by your build servers.');
    }
}
