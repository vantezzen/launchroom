<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Data\Protocol\V1\RequiredSecret;
use App\Models\Deployment;
use App\Services\Crypto\ProjectKeyring;
use App\Support\Variables\MissingSecretsMessage;

/**
 * Secrets the build says the app can't start without and launchroom can't
 * generate (`summary.requiredSecrets`: Rails's RAILS_MASTER_KEY). Checked
 * after the build, before the release command or a workload runs: a missing
 * one stops the deployment with a message that says what to add, instead of
 * a crash loop. Fork previews get no secrets (docs/09 R20) and aren't
 * checked. Names only, never values.
 */
final readonly class CheckRequiredSecrets
{
    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    /**
     * @return string|null why the deployment can't go on; null when nothing is missing
     */
    public function handle(Deployment $deployment): ?string
    {
        $required = $deployment->plan?->summary->requiredSecrets ?? [];
        $snapshot = $deployment->env_snapshot;

        if ($required === [] || $snapshot === null) {
            return null;
        }

        $deployment->loadMissing(['environment.project', 'serviceInstance.service']);

        if ($deployment->environment->secrets_withheld) {
            return null;
        }

        $env = $this->keyring->decryptSnapshot($deployment->environment->project, $deployment->public_id, $snapshot);
        $missing = array_values(array_filter($required, static fn (RequiredSecret $secret): bool => ($env[$secret->name] ?? '') === ''));

        return $missing === [] ? null : MissingSecretsMessage::for($deployment->serviceInstance->service->name, $missing);
    }
}
