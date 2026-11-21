<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Api\CreateRobotTokenInput;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\ApiTokenClient;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\Api\ApiTokenIssuer;
use App\Support\Api\NewApiToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a robot of the team and its token (docs/16 §1.4, docs/09 R45):
 * a `users` row owned by the team that can't sign in (random password
 * nobody sees, unverified `.invalid` address, no membership), and one
 * token with the creator's choice of abilities (never beyond the creator's
 * role) and scope (a project of the team, or one of its services).
 */
final readonly class CreateRobotToken
{
    public function __construct(
        private RecordAuditEvent $audit,
        private ApiTokenIssuer $issuer,
    ) {}

    public function handle(User $creator, Team $team, CreateRobotTokenInput $input, AuditActor $actor): NewApiToken
    {
        $role = $creator->roleIn($team) ?? throw DomainRuleViolation::forbidden("You aren't a member of that team.");
        [$project, $service] = $this->scope($team, $input);
        $name = mb_substr(trim($input->name), 0, 80);

        return DB::transaction(function () use ($creator, $team, $role, $input, $project, $service, $name, $actor): NewApiToken {
            $robot = new User;
            $robot->forceFill([
                'name' => $name,
                'email' => 'robot-'.Str::lower(Str::random(24)).'@robots.launchroom.invalid',
                'password' => Hash::make(Str::random(64)),
                'robot_team_id' => $team->id,
                'robot_created_by_id' => $creator->id,
                'current_team_id' => $team->id,
            ])->save();

            $new = $this->issuer->issue($robot, $team, $role, $name, $input->abilityList(), $input->expiresInDays, ApiTokenClient::Robot, $actor->ip, $project, $service, $input->fullAccess);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RobotTokenCreated,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'api_token',
                subjectId: $new->token->public_id,
                meta: [
                    'name' => $name,
                    'abilities' => $new->token->abilities,
                    'full_access' => $input->fullAccess,
                    'expires_in_days' => $input->expiresInDays,
                    'project' => $project?->public_id,
                    'service' => $service?->public_id,
                ],
            ));

            return $new;
        });
    }

    /**
     * @return array{Project|null, Service|null}
     */
    private function scope(Team $team, CreateRobotTokenInput $input): array
    {
        if ($input->project === null) {
            if ($input->service !== null) {
                throw DomainRuleViolation::on('service', 'Pick the service’s project first.');
            }

            return [null, null];
        }

        $project = Project::query()->where('team_id', $team->id)->where('public_id', $input->project)->first()
            ?? throw DomainRuleViolation::on('project', 'That project isn’t in this team.');

        if ($input->service === null) {
            return [$project, null];
        }

        $service = Service::query()->where('project_id', $project->id)->where('public_id', $input->service)->first()
            ?? throw DomainRuleViolation::on('service', 'That service isn’t in this project.');

        return [$project, $service];
    }
}
