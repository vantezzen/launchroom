<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Ingress\SetSharedEdge;
use App\Actions\Servers\UpdateServerRoles;
use App\Actions\Servers\UpdateServerSettings;
use App\Data\Audit\AuditActor;
use App\Data\Servers\UpdateServerRolesData;
use App\Data\Servers\UpdateServerSettingsData;
use App\Enums\ServerRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\Server;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Admin CLI for a server's roles and labels (docs/02 §2.2), e.g. for
 * scripted setups: the same actions and rules as Servers → server, audited
 * as the system. `--labels` replaces the whole label set ("" clears it);
 * options left out stay as they are. `--shared-edge` admits an edge to the
 * shared certificate storage (SetSharedEdge, docs/05 §5), approved in the
 * name of the instance owner (a host shell is instance-owner access).
 * Prints the result as JSON.
 */
#[Signature('launchroom:servers:update {server : Public id (srv_…) or name of a server that is not revoked} {--roles= : Comma-separated roles (edge, runtime, build)} {--labels= : Comma-separated key=value labels, replacing the current ones} {--shared-edge= : yes|no: admit the edge to the shared certificate storage (approved as the instance owner)}')]
#[Description('Change a server\'s roles and/or labels (same rules as the server page)')]
final class UpdateServerCommand extends Command
{
    public function handle(UpdateServerRoles $updateRoles, UpdateServerSettings $updateSettings, SetSharedEdge $setSharedEdge): int
    {
        $ref = $this->argument('server');
        $ref = is_string($ref) ? $ref : '';
        $servers = Server::query()
            ->whereNull('revoked_at')
            ->where(static fn (Builder $query) => $query->where('public_id', $ref)->orWhere('name', $ref))
            ->get();

        if ($servers->count() !== 1) {
            $this->components->error($servers->isEmpty() ? "No server {$ref} (that is not revoked)." : "Several servers are called {$ref}: use the public id.");

            return self::FAILURE;
        }

        $server = $servers->sole();
        $roles = $this->option('roles');
        $labels = $this->option('labels');

        $sharedEdge = $this->option('shared-edge');

        if (is_string($sharedEdge) && ! in_array($sharedEdge, ['yes', 'no'], true)) {
            $this->components->error('--shared-edge must be yes or no.');

            return self::INVALID;
        }

        if (! is_string($roles) && ! is_string($labels) && ! is_string($sharedEdge)) {
            $this->components->error('Nothing to change: pass --roles, --labels and/or --shared-edge.');

            return self::INVALID;
        }

        try {
            if (is_string($roles)) {
                $data = UpdateServerRolesData::validateAndCreate(['roles' => array_values(array_filter(array_map(trim(...), explode(',', $roles))))]);
                $server = $updateRoles->handle($server, $data, AuditActor::system());
            }

            if (is_string($labels)) {
                $parsed = [];

                foreach (array_filter(array_map(trim(...), explode(',', $labels))) as $pair) {
                    [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                    $parsed[$key] = $value;
                }

                $server = $updateSettings->handle($server, new UpdateServerSettingsData($parsed, $server->allow_previews, $server->allow_production), AuditActor::system());
            }

            if (is_string($sharedEdge)) {
                $owner = User::query()->where('is_instance_owner', true)->orderBy('id')->first();

                if (! $owner instanceof User) {
                    $this->components->error('No instance owner yet: finish /setup first.');

                    return self::FAILURE;
                }

                $server = $setSharedEdge->handle($server, $sharedEdge === 'yes', $owner, AuditActor::system());
            }
        } catch (ValidationException|DomainRuleViolation $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $server->refresh();

        $this->line(json_encode([
            'serverId' => $server->public_id,
            'name' => $server->name,
            'roles' => array_map(static fn (ServerRole $role): string => $role->value, $server->roles->all()),
            'labels' => (object) $server->labels,
            'sharedEdge' => $server->is_control_plane || $server->shared_edge_approved_at !== null,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
