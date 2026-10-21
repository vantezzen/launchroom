<?php

declare(strict_types=1);

use App\Actions\ConfigAsCode\ApplyConfigFile;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Services\GitSource;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\ConfigAsCode\ConfigTrust;
use App\Enums\TeamRole;
use App\Models\ServiceInstance;
use App\Models\Team;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    Projects::server($this->team);
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->web = Projects::image($this->project, 'web');
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $this->apply = fn (array $web) => resolve(ApplyConfigFile::class)->handle(
        $this->production,
        (string) json_encode(['version' => 1, 'services' => ['web' => ['kind' => 'image', ...$web]]]),
        'launchroom.json',
        new GitSource(555, 'acme/shop', 1),
        str_repeat('c', 40),
        ConfigTrust::Trusted,
        AuditActor::system(),
    );
});

test('settings pages show what launchroom.json manages and where to edit it', function (): void {
    ($this->apply)(['scaling' => ['replicas' => 2], 'variables' => ['LOG' => 'info']]);

    $this->actingAs($this->viewer)
        ->get(route('projects.services.section', [$this->project, $this->production, $this->web, 'scaling']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('panel.settings.configFile.fields', ['scaling.replicas'])
            ->where('panel.settings.configFile.variables', ['LOG'])
            ->where('panel.settings.configFile.editUrl', 'https://github.com/acme/shop/blob/'.str_repeat('c', 40).'/launchroom.json#L1'));

    $variables = $this->actingAs($this->owner)
        ->getJson(route('variables.index', [$this->project, $this->production, $this->web]))
        ->assertOk()
        ->json('variables');
    $log = collect($variables)->firstWhere('key', 'LOG');

    expect($log['managedByFile'])->toBeTrue()
        ->and($log['canEdit'])->toBeFalse();
});

test('a dashboard edit of a managed setting is marked on the staged changes', function (): void {
    ($this->apply)(['scaling' => ['replicas' => 2]]);
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $instance = ServiceInstance::query()->where('service_id', $this->web->id)->sole();
    resolve(UpdateServiceSettings::class)->handle($instance, new UpdateServiceSettingsData(scaling: new ScalingSettings(replicas: 5, cpus: 2)));

    $this->actingAs($this->owner)
        ->getJson(route('staged-changes.summary', [$this->project, $this->production]))
        ->assertOk()
        ->assertJsonPath('configFile.path', 'launchroom.json')
        ->assertJsonPath('configFile.managedPaths', ["services.{$this->web->public_id}.scaling.replicas"]);
});

test('project settings list each environment\'s last read; download and check now are authorized', function (): void {
    ($this->apply)(['scaling' => ['replicas' => 2]]);

    $this->actingAs($this->viewer)
        ->get(route('projects.settings', [$this->project, 'general']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('configFile.settings.path', 'launchroom.json')
            ->where('configFile.environments.0.status', 'staged')
            ->where('configFile.environments.0.managedSettings', 1)
            ->where('configFile.environments.0.canSync', false)
            ->where('configFile.canUpdate', false));

    $download = $this->actingAs($this->viewer)->get(route('projects.config.download', [$this->project, $this->production]));
    $download->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="launchroom.json"');
    expect(json_decode((string) $download->getContent(), true)['services']['web']['scaling'])->toBe(['replicas' => 2]);

    $this->actingAs($this->viewer)->post(route('projects.config.sync', [$this->project, $this->production]))->assertForbidden();
    $this->actingAs($this->viewer)->patch(route('projects.config.update', $this->project), ['enabled' => false, 'path' => 'launchroom.json'])->assertForbidden();

    $this->actingAs($this->owner)->patch(route('projects.config.update', $this->project), ['enabled' => true, 'path' => 'deploy/launchroom.json'])->assertRedirect();
    expect($this->project->refresh()->settings->configFile->path)->toBe('deploy/launchroom.json');

    $this->actingAs($this->owner)->patch(route('projects.config.update', $this->project), ['enabled' => true, 'path' => '../etc/passwd.json'])->assertSessionHasErrors('path');
});

test('another team can\'t download an environment\'s file', function (): void {
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($stranger)->get(route('projects.config.download', [$this->project, $this->production]))->assertNotFound();
});
