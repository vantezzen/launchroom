<?php

declare(strict_types=1);

namespace App\Support\AppUpdates;

use App\Data\AppUpdates\UpdateCandidateData;
use App\Enums\AppUpdates\UpdateKind;
use App\Support\Images\ImageReference;
use App\Support\Images\ImageVersion;

/**
 * Which of a check's candidates to lead with (docs/04 §14.4), pure: the
 * biggest update that isn't a major (a minor over a patch over a build),
 * else the major. A major is always its own decision.
 */
final class UpdateChoice
{
    /**
     * @param  iterable<UpdateCandidateData>  $candidates
     */
    public static function recommended(iterable $candidates): ?UpdateCandidateData
    {
        $best = null;

        foreach ($candidates as $candidate) {
            if ($best === null || self::rank($candidate) > self::rank($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * The newest version on offer, a major included (the biggest change).
     *
     * @param  iterable<UpdateCandidateData>  $candidates
     */
    public static function newest(iterable $candidates): ?UpdateCandidateData
    {
        foreach ($candidates as $candidate) {
            return $candidate;
        }

        return null;
    }

    /**
     * @param  iterable<UpdateCandidateData>  $candidates
     */
    public static function major(iterable $candidates): ?UpdateCandidateData
    {
        foreach ($candidates as $candidate) {
            if ($candidate->kind === UpdateKind::Major) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether a candidate is news for the `update_available` digest: a new
     * version, or a new image behind a moving tag (`latest`, `6`). A version
     * tag pushed again (a base image fix) shows on the service but isn't
     * worth a message.
     */
    public static function isNews(UpdateCandidateData $candidate): bool
    {
        if ($candidate->kind !== UpdateKind::Build) {
            return true;
        }

        $version = ImageVersion::parse($candidate->tag);

        return ! $version instanceof ImageVersion || $version->isShort();
    }

    /**
     * The running version for people: `v6.2.0` from
     * `docker.io/listmonk/listmonk:v6.2.0`, else the tag.
     */
    public static function runningVersion(?string $image): ?string
    {
        $tag = $image === null ? null : ImageReference::tryParse($image)?->tag;

        if ($tag === null) {
            return null;
        }

        $version = ImageVersion::parse($tag);

        return $version instanceof ImageVersion && ! $version->isShort() ? $version->display() : $tag;
    }

    /**
     * Whether the running tag moves with new releases (`latest`, `6`).
     */
    public static function isMoving(?string $image): bool
    {
        $tag = $image === null ? null : ImageReference::tryParse($image)?->tag;

        if ($tag === null) {
            return false;
        }

        $version = ImageVersion::parse($tag);

        return ! $version instanceof ImageVersion || $version->isShort();
    }

    private static function rank(UpdateCandidateData $candidate): int
    {
        // Major last: lead with the update that keeps settings and data as they are.
        return $candidate->kind === UpdateKind::Major ? -1 : $candidate->kind->rank();
    }
}
