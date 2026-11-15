<?php

declare(strict_types=1);

use App\Data\Deployments\DeploymentCommit;
use App\Data\Git\CommitData;
use App\Enums\Git\GitProviderKind;
use App\Models\Deployment;
use App\Models\Team;
use App\Services\Git\AvatarOrigins;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\GitProviderFake;
use Tests\Support\PreviewFlow;

/*
| Commit author avatars come from the provider that parsed the commit
| (docs/19 §7): a GitLab or Gitea avatar is kept, and no github.com avatar is
| made up from another provider's login. Readers show only avatars on an
| origin the CSP allows.
*/

const CA_SHA = 'cccccccccccccccccccccccccccccccccccccccc';

test('a pushed commit keeps its provider’s avatar and never gets one derived from its login', function (): void {
    $gitlab = GitProviderFake::GITLAB.'/uploads/-/system/user/avatar/7/avatar.png';

    expect(DeploymentCommit::pushed(new CommitData(CA_SHA, 'Fix', 'Ada', null, 'ada', $gitlab), 'refs/heads/main')->authorAvatarUrl)->toBe($gitlab)
        ->and(DeploymentCommit::pushed(new CommitData(CA_SHA, 'Fix', 'Ada', null, 'ada'), 'refs/heads/main')->authorAvatarUrl)->toBeNull();
});

test('readers show avatars only on an origin the CSP allows', function (): void {
    GitProviderFake::setUp();
    GitProviderFake::connection(Team::factory()->create(), GitProviderKind::GitLab);

    expect(AvatarOrigins::allowed(GitProviderFake::GITLAB.'/uploads/avatar.png'))->toBe(GitProviderFake::GITLAB.'/uploads/avatar.png')
        ->and(AvatarOrigins::allowed('https://avatars.githubusercontent.com/u/1?v=4'))->toBe('https://avatars.githubusercontent.com/u/1?v=4')
        ->and(AvatarOrigins::allowed('https://evil.example.net/avatar.png'))->toBeNull()
        ->and(AvatarOrigins::allowed('http://avatars.githubusercontent.com/u/1'))->toBeNull()
        ->and(AvatarOrigins::allowed(null))->toBeNull();
});

test('a preview from a provider other than GitHub gets no github.com avatar for its author', function (): void {
    PreviewFlow::setUp();
    [, $server, , $project, , , $web] = PreviewFlow::project();

    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);

    $deployment = Deployment::query()->where('environment_id', $preview->environment_id)->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $web->id))->latest('id')->sole();

    expect($preview->provider)->toBe(GitProviderKind::Git)
        ->and($deployment->commit_author)->toBe('ada')
        ->and($deployment->commit_author_avatar_url)->toBeNull();
});
