<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Enums\AppUpdates\UpdateKind;

/**
 * Which newer tags to offer for a running tag (docs/04 §14.1), pure: the
 * newest patch of the running minor, the newest minor of the running major
 * and the newest major, all in the running tag's channel, pre-releases only
 * for a pre-release. Pins of launchroom's templates (curated, tested) join
 * when they are newer in the same channel.
 *
 * A tag that isn't a version (`latest`) has no candidates here; whether it
 * moved is a question of digests, answered by the check itself.
 */
final class ReleaseCandidates
{
    /**
     * @param  list<string>  $tags  every tag of the repository
     * @param  list<CuratedPin>  $pins  template pins of the same repository
     * @return list<ReleaseCandidate> biggest change first
     */
    public static function for(string $running, array $tags, array $pins = []): array
    {
        $current = ImageVersion::parse($running);

        if (! $current instanceof ImageVersion) {
            return [];
        }

        /** @var array<string, ImageVersion> $best kind => newest */
        $best = [];

        foreach ($tags as $tag) {
            $version = self::comparable($current, $tag);

            if (! $version instanceof ImageVersion) {
                continue;
            }

            $kind = $current->kindTo($version)->value;

            if (! isset($best[$kind]) || $version->isNewerThan($best[$kind])) {
                $best[$kind] = $version;
            }
        }

        $candidates = [];

        foreach ($best as $kind => $version) {
            $candidates[$version->tag] = new ReleaseCandidate(UpdateKind::from($kind), $version->tag, $version->display());
        }

        foreach ($pins as $pin) {
            $version = self::comparable($current, $pin->tag);

            if (! $version instanceof ImageVersion) {
                continue;
            }

            $candidates[$pin->tag] = new ReleaseCandidate($current->kindTo($version), $pin->tag, $version->display(), $pin->templateName, $pin->digest);
        }

        $candidates = array_values($candidates);
        usort($candidates, static function (ReleaseCandidate $a, ReleaseCandidate $b): int {
            $versionA = ImageVersion::parse($a->tag);
            $versionB = ImageVersion::parse($b->tag);
            $byVersion = $versionA instanceof ImageVersion && $versionB instanceof ImageVersion ? $versionB->compareTo($versionA) : 0;

            return [$b->kind->rank(), $byVersion] <=> [$a->kind->rank(), 0];
        });

        return $candidates;
    }

    /**
     * $tag as a version newer than $current in its channel, else null.
     */
    private static function comparable(ImageVersion $current, string $tag): ?ImageVersion
    {
        $version = ImageVersion::parse($tag);

        if (! $version instanceof ImageVersion || $version->channel() !== $current->channel()) {
            return null;
        }

        if ($version->prerelease && ! $current->prerelease) {
            return null;
        }

        return $version->isNewerThan($current) ? $version : null;
    }
}
