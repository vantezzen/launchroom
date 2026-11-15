<?php

declare(strict_types=1);

use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Models\Team;
use App\Services\Git\AvatarOrigins;
use Tests\Support\GitProviderFake;

/*
| The CSP's img-src lists active connections' avatar origins (docs/19 §7):
| the cached list follows connections becoming active, leaving active and
| being deleted.
*/

beforeEach(function (): void {
    GitProviderFake::setUp();
});

test('the cached avatar origins follow connection status changes and deletions', function (): void {
    $connection = GitProviderFake::connection(Team::factory()->create(), GitProviderKind::GitLab, GitConnectionStatus::Pending);

    expect(AvatarOrigins::all())->not->toContain(GitProviderFake::GITLAB);

    $connection->transitionTo(GitConnectionStatus::Active);
    expect(AvatarOrigins::all())->toContain(GitProviderFake::GITLAB);

    $connection->transitionTo(GitConnectionStatus::NeedsReauth, 'expired');
    expect(AvatarOrigins::all())->not->toContain(GitProviderFake::GITLAB);

    $connection->transitionTo(GitConnectionStatus::Active);
    expect(AvatarOrigins::all())->toContain(GitProviderFake::GITLAB);

    $connection->delete();
    expect(AvatarOrigins::all())->not->toContain(GitProviderFake::GITLAB);
});
