<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\SigningKey;
use App\Services\Crypto\Signer;
use Illuminate\Support\Facades\DB;

/**
 * Records the public half of the control-plane signing key in `signing_keys`
 * (idempotent). The private key stays in its file.
 */
final readonly class RegisterSigningKey
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Signer $signer, AuditActor $actor): SigningKey
    {
        return DB::transaction(function () use ($signer, $actor): SigningKey {
            $existing = SigningKey::query()->where('kid', $signer->kid)->lockForUpdate()->first();

            if ($existing !== null) {
                if (! hash_equals($existing->public_key, $signer->publicKey())) {
                    throw DomainRuleViolation::conflict("Signing key id [{$signer->kid}] is already registered with a different public key.");
                }

                return $existing;
            }

            $key = SigningKey::query()->create(['kid' => $signer->kid, 'public_key' => $signer->publicKey()]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::SigningKeyCreated,
                actor: $actor,
                subjectType: 'signing_key',
                subjectId: $signer->kid,
                meta: ['public_key' => $signer->publicKey()],
            ));

            return $key;
        });
    }
}
