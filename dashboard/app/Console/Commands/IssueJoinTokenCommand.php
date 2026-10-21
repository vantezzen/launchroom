<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\CreateEnrollment;
use App\Data\Audit\AuditActor;
use App\Data\Servers\CreateEnrollmentData;
use App\Models\Server;
use App\Models\Team;
use App\Services\Crypto\Signer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Join token for another server from the command line, like "Add server" in
 * the UI (docs/03 §3). Used by `make dev-enroll-2`; the token goes to stdout
 * as JSON {"token", "cpKey", "kid"} and is never logged.
 */
#[Signature('launchroom:servers:join-token {--roles=runtime : Comma-separated roles (edge, runtime, build)} {--name= : Display name} {--team= : Public id of the team (default: the control-plane server\'s team)}')]
#[Description('Create a join token for another server and print it as JSON')]
final class IssueJoinTokenCommand extends Command
{
    public function handle(CreateEnrollment $createEnrollment, Signer $signer): int
    {
        $teamOption = $this->option('team');
        $team = is_string($teamOption) && $teamOption !== ''
            ? Team::query()->where('public_id', $teamOption)->first()
            : Team::query()->whereKey(Server::query()->where('is_control_plane', true)->whereNull('revoked_at')->value('team_id'))->first();

        if (! $team instanceof Team) {
            $this->components->error('No team found: enroll the control-plane server first, or pass --team.');

            return self::FAILURE;
        }

        $roles = array_values(array_filter(array_map(trim(...), explode(',', is_string($this->option('roles')) ? $this->option('roles') : ''))));
        $name = $this->option('name');

        try {
            $data = CreateEnrollmentData::validateAndCreate(['roles' => $roles, 'name' => is_string($name) && $name !== '' ? $name : null]);
        } catch (ValidationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $issued = $createEnrollment->handle($team, $data, null, AuditActor::system());

        $this->line(json_encode([
            'token' => $issued->token,
            'cpKey' => $signer->publicKey(),
            'kid' => $signer->kid,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
