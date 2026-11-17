<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Registry\RegistryCredentialInput;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Registry\RegistryCredential;
use App\Models\Team;
use App\Models\User;
use App\Services\Crypto\SealedColumns;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * Adds a team's sign-in details for a private registry, or changes them
 * ($credential given; the host stays, a blank password keeps the stored
 * one). The password or token is envelope encrypted (docs/09 R13); the audit
 * entry names the host and user only. The caller authorizes.
 */
final readonly class SaveRegistryCredential
{
    public function __construct(
        private SealedColumns $sealed,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Team $team, RegistryCredentialInput $input, User $user, AuditActor $actor, ?RegistryCredential $credential = null): RegistryCredential
    {
        $host = $credential instanceof RegistryCredential ? $credential->host : RegistryCredential::normalizeHost($input->host);

        if (! RegistryCredential::isValidHost($host)) {
            throw DomainRuleViolation::on('host', 'Enter the registry’s address as it appears in image names, for example ghcr.io or registry.gitlab.com (no https://, no port).');
        }

        if ($credential instanceof RegistryCredential && $credential->team_id !== $team->id) {
            throw DomainRuleViolation::forbidden('This registry belongs to another team.');
        }

        $username = trim($input->username);
        $password = $input->password ?? '';

        if ($username === '') {
            throw DomainRuleViolation::on('username', 'Enter the username to sign in with.');
        }

        if (! $credential instanceof RegistryCredential && $password === '') {
            throw DomainRuleViolation::on('password', 'Enter a password or an access token that can read the images.');
        }

        return DB::transaction(function () use ($team, $host, $username, $password, $user, $actor, $credential): RegistryCredential {
            $creating = ! $credential instanceof RegistryCredential;

            if ($creating && RegistryCredential::query()->where('team_id', $team->id)->where('host', $host)->lockForUpdate()->exists()) {
                throw DomainRuleViolation::on('host', "This team already has sign-in details for {$host}. Change those instead.");
            }

            $credential ??= new RegistryCredential(['team_id' => $team->id, 'host' => $host, 'created_by' => $user->id]);

            if ($creating) {
                $credential->public_id = PublicId::generate(RegistryCredential::publicIdPrefix());
            }

            $changed = $creating || $username !== $credential->username || $password !== '';
            $credential->username = $username;

            if ($password !== '') {
                $this->sealed->seal($credential, 'secret', $password, $this->sealed->newKey($credential));
            }

            if ($changed) {
                $credential->forgetCheck();
            }

            $credential->save();

            $this->audit->handle(new AuditEntryData(
                action: $creating ? AuditAction::RegistryCredentialCreated : AuditAction::RegistryCredentialUpdated,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'registry_credential',
                subjectId: $credential->public_id,
                meta: ['host' => $host, 'username' => $username, 'secretChanged' => $password !== ''],
            ));

            return $credential;
        });
    }
}
