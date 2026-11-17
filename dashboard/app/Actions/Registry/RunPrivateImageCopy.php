<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Enums\Registry\RegistryCopyStatus;
use App\Enums\Registry\RegistryCredentialStatus;
use App\Models\Registry\RegistryCredential;
use App\Models\Registry\RegistryImageCopy;
use App\Services\Crypto\SealedColumns;
use App\Services\Registry\PrivateImageCopier;
use App\Services\Registry\UpstreamAuth;
use App\Services\Registry\UpstreamRegistryError;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one queued private image copy (CopyPrivateImageJob): pending →
 * copying → succeeded or failed with a message for the deployment. A
 * refused sign-in also marks the team's credential as failing. Idempotent:
 * anything but a pending copy is left alone.
 */
final readonly class RunPrivateImageCopy
{
    public function __construct(
        private SealedColumns $sealed,
        private PrivateImageCopier $copier,
    ) {}

    public function handle(int $copyId): void
    {
        $copy = DB::transaction(static function () use ($copyId): ?RegistryImageCopy {
            $copy = RegistryImageCopy::query()->whereKey($copyId)->lockForUpdate()->first();

            if (! $copy instanceof RegistryImageCopy || $copy->status !== RegistryCopyStatus::Pending) {
                return null;
            }

            $copy->transitionTo(RegistryCopyStatus::Copying);

            return $copy;
        });

        if (! $copy instanceof RegistryImageCopy) {
            return;
        }

        $credential = $copy->credential;

        if (! $credential instanceof RegistryCredential || $credential->team_id !== $copy->team_id) {
            $copy->transitionTo(RegistryCopyStatus::Failed, 'The sign-in details for this registry were removed. Add them again in Settings → Private registries.');

            return;
        }

        [$host, $repository] = explode('/', $copy->source, 2);

        try {
            $result = $this->copier->copy(
                new UpstreamAuth($host, $credential->username, (string) $this->sealed->reveal($credential, 'secret')),
                $repository,
                $copy->reference,
                RegistryImageCopy::mirrorRepository($copy->team, $copy->source),
            );
        } catch (UpstreamRegistryError $e) {
            if ($e->isSignInProblem()) {
                $credential->markChecked(RegistryCredentialStatus::Failed, $e->getMessage());
            }

            $copy->transitionTo(RegistryCopyStatus::Failed, $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $copy->transitionTo(RegistryCopyStatus::Failed, "launchroom couldn’t copy {$copy->source}. Deploy again to retry.");

            return;
        }

        $copy->forceFill(['digest' => $result['digest'], 'bytes' => $result['bytes']]);
        $copy->transitionTo(RegistryCopyStatus::Succeeded);

        if ($credential->status !== RegistryCredentialStatus::Ok) {
            $credential->markChecked(RegistryCredentialStatus::Ok);
        }
    }
}
