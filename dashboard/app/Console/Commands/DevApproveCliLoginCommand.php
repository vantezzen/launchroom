<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Api\ApproveCliLogin;
use App\Data\Api\ApproveCliLoginInput;
use App\Data\Audit\AuditActor;
use App\Enums\ApiAbility;
use App\Models\CliLoginRequest;
use App\Models\User;
use App\Support\Api\CliUserCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Local dev only: approves a pending CLI sign-in as a user in their current
 * team, for the CLI e2e test (`make test-cli-e2e`, docs/16 §5.2). Prints no
 * secret. Refuses outside `local`.
 */
#[Signature('launchroom:dev:cli-approve {code : The user code the CLI printed (WDJB-MJHT)} {--email= : Approve as this user (default: the first instance owner)} {--abilities= : Comma-separated abilities (default: the CLI defaults)} {--days=90 : Token lifetime in days}')]
#[Description('Local dev only: approve a pending CLI sign-in')]
final class DevApproveCliLoginCommand extends Command
{
    public function handle(ApproveCliLogin $approve): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs in the local dev environment.');

            return self::FAILURE;
        }

        $argument = $this->argument('code');
        $code = is_string($argument) ? CliUserCode::normalize($argument) : null;
        $login = $code === null ? null : CliLoginRequest::query()->where('user_code', $code)->first();

        if (! $login instanceof CliLoginRequest) {
            $this->components->error('No sign-in request with that code.');

            return self::FAILURE;
        }

        $email = $this->option('email');
        $user = is_string($email) && $email !== ''
            ? User::query()->where('email', $email)->first()
            : User::query()->where('is_instance_owner', true)->orderBy('id')->first();
        $team = $user?->currentTeam;

        if (! $user instanceof User || $team === null) {
            $this->components->error('No such user, or the user has no current team.');

            return self::FAILURE;
        }

        $abilities = $this->option('abilities');
        $list = is_string($abilities) && $abilities !== ''
            ? array_map(trim(...), explode(',', $abilities))
            : array_map(static fn (ApiAbility $ability): string => $ability->value, ApiAbility::defaults());

        $approve->handle($login, $user, ApproveCliLoginInput::validateAndCreate([
            'team' => $team->public_id,
            'abilities' => $list,
            'expiresInDays' => (int) $this->option('days'),
        ]), AuditActor::user($user));

        $this->components->info("Approved {$code} for {$user->email} in {$team->name}.");

        return self::SUCCESS;
    }
}
