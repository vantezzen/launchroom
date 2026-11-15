<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\PushPayload;
use App\Enums\Git\GitEventSource;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubInstallation;
use App\Support\Git\GitRef;

/**
 * A push webhook of any provider as one {@see ObserveRefHead}: refs outside
 * the git grammar are ignored, a zero or malformed `after` deletes the ref.
 */
final readonly class ObservePush
{
    public function __construct(
        private ObserveRefHead $observe,
    ) {}

    /**
     * @return bool whether an event was emitted
     */
    public function handle(GitHubInstallation|GitConnection $account, PushPayload $push): bool
    {
        if (! GitRef::isValid($push->ref)) {
            return false;
        }

        $deleted = ! GitRef::isSha($push->after) || GitRef::isZeroSha($push->after);

        return $this->observe->handle($account, $push->repositoryId, $push->ref, $deleted ? null : $push->headCommit, GitEventSource::Webhook, $push->before, $deleted ? null : $push->changedFiles, $push->forced);
    }
}
