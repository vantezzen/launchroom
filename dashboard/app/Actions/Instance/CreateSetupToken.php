<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Support\Crypto\OpaqueToken;
use Illuminate\Support\Facades\DB;

/**
 * Creates the one-time first-run setup token (docs/09 R10). Only the hash is
 * stored; a new token replaces any previous one.
 */
final readonly class CreateSetupToken
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(int $ttlMinutes, AuditActor $actor): string
    {
        return DB::transaction(function () use ($ttlMinutes, $actor): string {
            $settings = InstanceSettings::lockedForUpdate();

            if ($settings->setup_completed_at !== null || User::query()->exists()) {
                throw DomainRuleViolation::conflict('Setup is already complete. Invite people from the team settings instead.');
            }

            $token = OpaqueToken::generate('lr_setup_');

            $settings->forceFill([
                'setup_token_hash' => OpaqueToken::hash($token),
                'setup_token_expires_at' => now()->addMinutes(max(1, $ttlMinutes)),
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::SetupTokenCreated,
                actor: $actor,
                meta: ['ttl_minutes' => $ttlMinutes],
            ));

            return $token;
        });
    }
}
