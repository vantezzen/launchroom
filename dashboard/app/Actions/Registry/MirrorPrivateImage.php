<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Data\Registry\PrivateImageResult;
use App\Enums\Registry\RegistryCopyStatus;
use App\Jobs\Registry\CopyPrivateImageJob;
use App\Models\Registry\RegistryCredential;
use App\Models\Registry\RegistryImageCopy;
use App\Support\Images\ImageReference;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Where a deployment gets a private image from (D91, docs/05 §6). When the
 * team has sign-in details for the image's registry host, the image is
 * copied into launchroom's registry under the team's mirror path by
 * CopyPrivateImageJob, and servers pull that copy with team-scoped tokens.
 * Without sign-in details for the host it returns null and the image goes
 * through the public mirror as before.
 *
 * - ready: the digest-pinned team mirror reference;
 * - pending: the copy is queued or running (unfinished deployments are
 *   advanced again every minute, so the caller just waits);
 * - failed: why, for the deployment. Asking again after a minute retries.
 *
 * A copy by tag is refreshed when the tag is asked for again more than
 * REFRESH_AFTER_SECONDS after it was copied (the tag may point elsewhere
 * now); a copy by digest never changes.
 */
final readonly class MirrorPrivateImage
{
    public const int REFRESH_AFTER_SECONDS = 600;

    public const int RETRY_AFTER_SECONDS = 60;

    /** A copy still "copying" after the job's timeout lost its worker. */
    public const int STUCK_AFTER_SECONDS = 3600 + 300;

    public function handle(int $teamId, ImageReference $reference): ?PrivateImageResult
    {
        $credential = RegistryCredential::forImage($teamId, $reference);

        if (! $credential instanceof RegistryCredential) {
            return null;
        }

        $source = strtolower($reference->registry.'/'.$reference->repository);
        $wanted = $reference->digest ?? $reference->tag ?? 'latest';

        $copy = $this->row($teamId, $credential, $source, $wanted);

        [$result, $dispatch] = DB::transaction(function () use ($copy, $credential, $reference): array {
            $copy = RegistryImageCopy::query()->whereKey($copy->id)->lockForUpdate()->firstOrFail();
            $copy->setRelation('team', $credential->team);
            $copy->registry_credential_id = $credential->id;

            return match ($copy->status) {
                RegistryCopyStatus::Succeeded => $reference->digest === null && $copy->finished_at !== null && $copy->finished_at->lt(now()->subSeconds(self::REFRESH_AFTER_SECONDS))
                    ? $this->restart($copy)
                    : [PrivateImageResult::ready((string) $copy->image()), false],
                RegistryCopyStatus::Failed => $copy->finished_at !== null && $copy->finished_at->lt(now()->subSeconds(self::RETRY_AFTER_SECONDS))
                    ? $this->restart($copy)
                    : [PrivateImageResult::failed((string) $copy->message), false],
                RegistryCopyStatus::Copying => $copy->started_at !== null && $copy->started_at->lt(now()->subSeconds(self::STUCK_AFTER_SECONDS))
                    ? $this->restart($copy)
                    : [PrivateImageResult::pending(), false],
                RegistryCopyStatus::Pending => [PrivateImageResult::pending(), true],
            };
        });

        if ($dispatch) {
            dispatch(new CopyPrivateImageJob($copy->id));
        }

        return $result;
    }

    private function row(int $teamId, RegistryCredential $credential, string $source, string $wanted): RegistryImageCopy
    {
        $find = static fn (): ?RegistryImageCopy => RegistryImageCopy::query()->where('team_id', $teamId)->where('source', $source)->where('reference', $wanted)->first();

        $copy = $find();

        if ($copy instanceof RegistryImageCopy) {
            return $copy;
        }

        try {
            return RegistryImageCopy::query()->create(['team_id' => $teamId, 'registry_credential_id' => $credential->id, 'source' => $source, 'reference' => $wanted]);
        } catch (UniqueConstraintViolationException) {
            return $find() ?? throw new \LogicException('The image copy disappeared.');
        }
    }

    /**
     * @return array{PrivateImageResult, bool}
     */
    private function restart(RegistryImageCopy $copy): array
    {
        $copy->transitionTo(RegistryCopyStatus::Pending);

        return [PrivateImageResult::pending(), true];
    }
}
