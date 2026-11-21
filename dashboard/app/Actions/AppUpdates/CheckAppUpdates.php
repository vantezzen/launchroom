<?php

declare(strict_types=1);

namespace App\Actions\AppUpdates;

use App\Data\AppUpdates\UpdateCandidateData;
use App\Data\Services\ImageSource;
use App\Enums\AppUpdates\UpdateCheckStatus;
use App\Enums\AppUpdates\UpdateKind;
use App\Events\CanvasChanged;
use App\Models\AppUpdates\AppUpdateCheck;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Services\AppUpdates\ImageRegistry;
use App\Services\AppUpdates\UpdatePlan;
use App\Services\Registry\UpstreamAuth;
use App\Services\Registry\UpstreamRegistryError;
use App\Services\Templates\TemplateCatalog;
use App\Support\Images\CuratedPin;
use App\Support\Images\ImageReference;
use App\Support\Images\ImageVersion;
use App\Support\Images\ReleaseCandidate;
use App\Support\Images\ReleaseCandidates;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * Checks one image service instance for newer releases (docs/04 §14.1) and
 * records the outcome in its `app_update_checks` row: the running version
 * (the tag of the live deployment's revision and the digest it ran), the
 * candidates in the tag's channel plus newer template pins, a build when
 * the tag now names another image, publish dates and the release notes
 * link. Idempotent; a registry error keeps the last known candidates and
 * backs off. Then the team's update digest is refreshed.
 */
final readonly class CheckAppUpdates
{
    public const int INTERVAL_HOURS = 12;

    public const int MAX_BACKOFF_HOURS = 24;

    public function __construct(
        private ImageRegistry $registry,
        private TemplateCatalog $catalog,
        private NotifyAvailableUpdates $notify,
    ) {}

    public function handle(int $instanceId, ?CarbonImmutable $now = null): ?AppUpdateCheck
    {
        $now ??= CarbonImmutable::now();
        $instance = ServiceInstance::query()->whereKey($instanceId)->with(['service', 'environment', 'activeDeployment.revision'])->first();

        if (! $instance instanceof ServiceInstance || ! UpdatePlan::isChecked($instance)) {
            return null;
        }

        $check = AppUpdateCheck::query()->firstOrNew(['service_instance_id' => $instance->id], ['team_id' => $instance->team_id]);
        $running = $this->running($instance);

        if (is_string($running)) {
            $this->record($instance, $check, $now, UpdateCheckStatus::Unknown, $running, [], null);
            $check->forceFill(['image' => null, 'running_digest' => null, 'running_published_at' => null])->save();

            return $check;
        }

        [$reference, $digest] = $running;
        /** @var string $tag */
        $tag = $reference->tag;
        // The running tag without its digest: what people call the version.
        $check->forceFill(['image' => $reference->registry.'/'.$reference->repository.':'.$tag, 'running_digest' => $digest]);
        $auth = $this->registry->auth($instance->team_id, $reference);

        if (! $auth instanceof UpstreamAuth) {
            $this->record($instance, $check, $now, UpdateCheckStatus::Unknown, "launchroom can’t read the versions on {$reference->registry}. Add its sign-in details in Settings → Private registries.", [], null);

            return $check;
        }

        $pins = $this->catalog->pinsFor($reference->registry, $reference->repository);

        try {
            $found = [...ReleaseCandidates::for($tag, $this->registry->tags($auth, $reference), $pins), ...$this->build($auth, $reference, $tag, $digest)];
        } catch (UpstreamRegistryError $e) {
            $this->failed($check, $now, $e, $pins, $tag, $reference->registry);
            $this->notify->handle($instance->team_id);

            return $check;
        }

        $candidates = $this->enrich($auth, $reference, $found, $check, $now);
        $running = $digest === null ? null : $this->registry->facts($auth, $reference, $tag, $digest, withSource: true);
        $check->forceFill(['running_published_at' => $running?->publishedAt]);
        $this->record($instance, $check, $now, $candidates === [] ? UpdateCheckStatus::Current : UpdateCheckStatus::Available, null, $candidates, $this->releaseNotes($pins, $running?->source));
        $this->notify->handle($instance->team_id);

        return $check;
    }

    /**
     * The running reference (with its tag) and the digest it ran, or why
     * there is nothing to compare.
     *
     * @return array{0: ImageReference, 1: string|null}|string
     */
    private function running(ServiceInstance $instance): array|string
    {
        $deployment = $instance->activeDeployment;
        try {
            $spec = $deployment?->revision?->spec->service($instance->service->public_id);
        } catch (Throwable) {
            // A revision written by an older release that no longer reads: the intent says what runs.
            $spec = null;
        }

        $source = $spec?->source() ?? $instance->service->source;
        $reference = $source instanceof ImageSource ? ImageReference::tryParse($source->reference) : null;

        if (! $reference instanceof ImageReference) {
            return 'This service doesn’t run an image launchroom can read.';
        }

        if ($reference->tag === null) {
            return 'Pinned by digest only, so there is no version to compare.';
        }

        $digest = $deployment instanceof Deployment && is_string($deployment->image) && str_contains($deployment->image, '@')
            ? substr($deployment->image, strrpos($deployment->image, '@') + 1)
            : null;

        return [$reference, $digest !== null && ImageReference::isDigest($digest) ? $digest : $reference->digest];
    }

    /**
     * A build: the running tag names another image now. For a tag that
     * isn't a full version (`latest`, `6`), name the version it points at
     * when the newest full version of its family has that digest.
     *
     * @return list<ReleaseCandidate>
     *
     * @throws UpstreamRegistryError
     */
    private function build(UpstreamAuth $auth, ImageReference $reference, string $tag, ?string $running): array
    {
        $now = $this->registry->digest($auth, $reference, $tag);

        if ($now === null || $running === null || hash_equals($running, $now)) {
            return [];
        }

        $version = ImageVersion::parse($tag);

        if ($version instanceof ImageVersion && ! $version->isShort()) {
            return [new ReleaseCandidate(UpdateKind::Build, $tag, $version->display(), digest: $now)];
        }

        $named = $this->newestFull($this->registry->tags($auth, $reference), $version);

        if ($named instanceof ImageVersion && $this->registry->digest($auth, $reference, $named->tag) === $now) {
            return [new ReleaseCandidate(UpdateKind::Build, $tag, $named->display(), digest: $now)];
        }

        return [new ReleaseCandidate(UpdateKind::Build, $tag, $tag, digest: $now)];
    }

    /**
     * The newest full version (three or more numbers, no pre-release) a
     * moving tag can stand for: of the same major for `6`, of any for a word.
     *
     * @param  list<string>  $tags
     */
    private function newestFull(array $tags, ?ImageVersion $moving): ?ImageVersion
    {
        $best = null;

        foreach ($tags as $candidate) {
            $version = ImageVersion::parse($candidate);

            if (! $version instanceof ImageVersion || $version->isShort() || $version->prerelease || $version->family !== ($moving->family ?? '')) {
                continue;
            }

            if ($moving instanceof ImageVersion && array_slice($version->numbers, 0, count($moving->numbers)) !== $moving->numbers) {
                continue;
            }

            if (! $best instanceof ImageVersion || $version->isNewerThan($best)) {
                $best = $version;
            }
        }

        return $best;
    }

    /**
     * Digest, publish date and first-seen time per candidate. A candidate
     * whose digest can't be read is left out (an update pins the digest).
     *
     * @param  list<ReleaseCandidate>  $found
     * @return list<UpdateCandidateData>
     */
    private function enrich(UpstreamAuth $auth, ImageReference $reference, array $found, AppUpdateCheck $check, CarbonImmutable $now): array
    {
        $candidates = [];

        foreach ($found as $candidate) {
            try {
                $digest = $candidate->digest ?? $this->registry->digest($auth, $reference, $candidate->tag);
            } catch (UpstreamRegistryError) {
                $digest = null;
            }

            if ($digest === null) {
                continue;
            }

            $previous = $check->exists ? $check->candidate($candidate->tag) : null;
            $facts = $this->registry->facts($auth, $reference, $candidate->tag, $digest);

            $candidates[] = new UpdateCandidateData(
                kind: $candidate->kind,
                tag: $candidate->tag,
                version: $candidate->version,
                digest: $digest,
                publishedAt: $facts->publishedAt?->toIso8601ZuluString(),
                firstSeenAt: $previous instanceof UpdateCandidateData && $previous->digest === $digest ? $previous->firstSeenAt : $now->toIso8601ZuluString(),
                curatedBy: $candidate->curatedBy,
            );
        }

        return $candidates;
    }

    /**
     * The registry didn't answer: keep what was known, offer newer
     * template pins on a first check, and try again later.
     *
     * @param  list<CuratedPin>  $pins
     */
    private function failed(AppUpdateCheck $check, CarbonImmutable $now, UpstreamRegistryError $error, array $pins, string $tag, string $host): void
    {
        $failures = $check->failures + 1;
        $hours = min(self::MAX_BACKOFF_HOURS, 2 ** ($failures - 1));
        $known = $check->exists && $check->candidates->count() > 0;

        if (! $known) {
            $curated = array_map(static fn (ReleaseCandidate $candidate): UpdateCandidateData => new UpdateCandidateData(
                kind: $candidate->kind,
                tag: $candidate->tag,
                version: $candidate->version,
                digest: $candidate->digest,
                publishedAt: null,
                firstSeenAt: $now->toIso8601ZuluString(),
                curatedBy: $candidate->curatedBy,
            ), ReleaseCandidates::for($tag, [], $pins));

            $check->candidates = new DataCollection(UpdateCandidateData::class, $curated);
            $check->status = $curated === [] ? UpdateCheckStatus::Failed : UpdateCheckStatus::Available;
        }

        $check->forceFill([
            'reason' => mb_substr($error->getMessage(), 0, 512),
            'failures' => $failures,
            'next_check_at' => $this->registry->heldUntil($host) ?? $now->addHours($hours),
        ])->save();
    }

    /**
     * @param  list<UpdateCandidateData>  $candidates
     */
    private function record(ServiceInstance $instance, AppUpdateCheck $check, CarbonImmutable $now, UpdateCheckStatus $status, ?string $reason, array $candidates, ?string $releaseNotes): void
    {
        $changed = ! $check->exists || $check->status !== $status || $check->candidates->count() !== count($candidates);

        $check->status = $status;
        $check->forceFill([
            'reason' => $reason,
            'candidates' => new DataCollection(UpdateCandidateData::class, $candidates),
            'release_notes_url' => $releaseNotes,
            'checked_at' => $now,
            'failures' => 0,
            // Spread over the hour so a fleet of services doesn't ask at once.
            'next_check_at' => $now->addHours($status === UpdateCheckStatus::Unknown ? 24 : self::INTERVAL_HOURS)->addMinutes(random_int(0, 59)),
        ])->save();

        if ($changed) {
            CanvasChanged::forInstance($instance);
        }
    }

    /**
     * The template's release notes, else the image's GitHub source.
     *
     * @param  list<CuratedPin>  $pins
     */
    private function releaseNotes(array $pins, ?string $source): ?string
    {
        foreach ($pins as $pin) {
            if ($pin->releases !== null) {
                return $pin->releases;
            }
        }

        if ($source !== null && preg_match('#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#D', $source, $m) === 1) {
            return "https://github.com/{$m[1]}/{$m[2]}/releases";
        }

        return null;
    }
}
