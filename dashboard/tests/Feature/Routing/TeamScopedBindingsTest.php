<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Http\Routing\RouteFamily;
use App\Http\Routing\TeamScopedBindings;
use App\Models\Backups\BackupDestination;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use App\Models\Volume;
use App\Support\PublicId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->member = memberOf($this->team, TeamRole::Developer);
    $this->project = Projects::create($this->team);
    $this->outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
});

test('raw ids resolve only inside the user\'s teams, malformed ones without a query', function (): void {
    $environment = $this->project->production()->sole();

    expect(TeamScopedBindings::find('environment', $environment->public_id, $this->member)?->is($environment))->toBeTrue()
        ->and(TeamScopedBindings::find('environment', $environment->public_id, $this->outsider))->toBeNull()
        ->and(TeamScopedBindings::find('environment', 'prj_'.substr($environment->public_id, 4), $this->member))->toBeNull()
        ->and(TeamScopedBindings::find('team', $this->team->public_id, $this->member)?->is($this->team))->toBeTrue()
        ->and(TeamScopedBindings::find('team', $this->team->public_id, $this->outsider))->toBeNull();

    DB::enableQueryLog();
    expect(TeamScopedBindings::find('environment', 'env_not-an-id', $this->member))->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

test('instance-level rows resolve for instance owners only', function (): void {
    Backups::fakeEnvironment();
    $owner = User::factory()->instanceOwner()->create();
    $destination = Backups::destination(null, $owner);
    expect($destination->team_id)->toBeNull();

    expect(TeamScopedBindings::find('backupDestination', $destination->public_id, $owner))->toBeInstanceOf(BackupDestination::class)
        ->and(TeamScopedBindings::find('backupDestination', $destination->public_id, $this->member))->toBeNull();
});

test('registered parameters get their route pattern from the model prefix', function (): void {
    $patterns = Route::getPatterns();

    expect($patterns['environment'])->toBe(PublicId::routePattern(Environment::publicIdPrefix()))
        ->and($patterns['volume'])->toBe(PublicId::routePattern(Volume::publicIdPrefix()))
        ->and($patterns['backupDestination'])->toBe(PublicId::routePattern(BackupDestination::publicIdPrefix()));
});

test('route families classify by path', function (string $path, RouteFamily $family): void {
    expect(RouteFamily::of(Request::create('/'.$path)))->toBe($family);
})->with([
    ['daemon/v1/state', RouteFamily::Daemon],
    ['move/v1/cpm_x', RouteFamily::Daemon],
    ['auth/preview', RouteFamily::Edge],
    ['registry/token', RouteFamily::Edge],
    ['up', RouteFamily::Probe],
    ['up/ready', RouteFamily::Probe],
    ['.well-known/launchroom/dashboard', RouteFamily::Probe],
    ['install/server.sh', RouteFamily::Installer],
    ['api/v1/me', RouteFamily::Api],
    ['cli/login', RouteFamily::Cli],
    ['webhooks/github', RouteFamily::Webhook],
    ['build/assets/app.js', RouteFamily::Asset],
    ['p/prj_x/settings', RouteFamily::Browser],
    ['', RouteFamily::Browser],
]);
