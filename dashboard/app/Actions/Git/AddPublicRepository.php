<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Audit\AuditActor;
use App\Data\Git\AddGitServerData;
use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Git\GitRepository;
use App\Models\Team;
use App\Models\User;
use App\Support\Git\PublicRepositoryUrl;
use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;

/**
 * "Paste a Git URL" → Continue (docs/19 §4.3, D70): the team's connection
 * for a public HTTPS repository, so the import flow (inspect, plan preview,
 * import) and polling treat it like any plain git repository. The same URL
 * reuses the team's existing connection; a new one is added through
 * {@see AddGitServer} (smart-HTTP check, audited), never with credentials
 * and never on a private network.
 */
final readonly class AddPublicRepository
{
    public function __construct(
        private CheckPublicRepository $check,
        private AddGitServer $add,
    ) {}

    /**
     * @throws DomainRuleViolation when the URL isn't a readable public repository
     */
    public function handle(Team $team, User $user, string $input, AuditActor $actor): GitRepository
    {
        $checked = $this->check->handle($input);

        if ($checked->problem !== null) {
            throw DomainRuleViolation::on('url', $checked->problem);
        }

        try {
            $url = PublicRepositoryUrl::parse($checked->url)->cloneUrl();
        } catch (InvalidArgumentException $e) {
            throw DomainRuleViolation::on('url', $e->getMessage());
        }

        $existing = GitRepository::query()
            ->where('team_id', $team->id)
            ->whereHas('gitConnection', static fn (Builder $connection) => $connection
                ->where('team_id', $team->id)
                ->where('provider', GitProviderKind::Git->value)
                ->where('auth_method', GitAuthMethod::None->value)
                ->where('status', GitConnectionStatus::Active->value)
                ->where('base_url', $url))
            ->first();

        if ($existing instanceof GitRepository) {
            $existing->forceFill(['default_branch' => $checked->defaultBranch ?? $existing->default_branch])->save();
            $existing->touch();

            return $existing;
        }

        $connection = $this->add->handle($team, $user, new AddGitServerData($url), $actor);

        return GitRepository::query()->where('git_connection_id', $connection->id)->sole();
    }
}
