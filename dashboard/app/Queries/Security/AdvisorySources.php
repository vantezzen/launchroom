<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Security\FeedStateData;
use App\Enums\Security\AdvisoryFeed;
use App\Models\Security\AdvisoryFeedState;

/**
 * The public advisory sources with their switch and last sync (docs/22
 * §4.4, §9.8).
 */
final class AdvisorySources
{
    /**
     * @return list<FeedStateData>
     */
    public static function all(): array
    {
        return array_map(static function (AdvisoryFeed $feed): FeedStateData {
            $state = AdvisoryFeedState::of($feed);

            return new FeedStateData(
                $feed->value,
                $feed->label(),
                $feed->host(),
                $state->enabled,
                $state->last_success_at?->toIso8601ZuluString(),
                $state->failing_since?->toIso8601ZuluString(),
                $state->last_error,
                $state->items,
            );
        }, AdvisoryFeed::cases());
    }
}
