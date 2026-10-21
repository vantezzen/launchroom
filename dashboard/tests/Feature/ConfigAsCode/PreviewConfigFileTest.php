<?php

declare(strict_types=1);

use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Enums\Previews\ForkPolicy;
use App\Enums\Previews\PreviewStatus;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\Domain;
use App\Models\Previews\PreviewSettings;
use App\Models\ServiceInstance;
use Tests\Support\ConfigFiles;
use Tests\Support\PreviewFlow;

beforeEach(fn () => PreviewFlow::setUp());

function pcFile(array $web): string
{
    return (string) json_encode(['version' => 1, 'services' => ['web' => ['kind' => 'app', ...$web]]]);
}

test('a pull request\'s launchroom.json configures its preview within the preview caps', function (): void {
    [, , , $project, $production, , $web] = PreviewFlow::project();
    ConfigFiles::serve([PreviewFlow::SHA_PR_1 => pcFile([
        'deploy' => ['startCommand' => ['node', 'server.js', '--preview']],
        'scaling' => ['replicas' => 4, 'placement' => ['labels' => ['gpu' => 'yes']]],
        'domains' => ['pr.example.com'],
    ])]);

    $preview = PreviewFlow::event($project);
    $instance = ServiceInstance::query()->where('environment_id', $preview->environment_id)->where('service_id', $web->id)->sole();
    $record = EnvironmentConfigFile::query()->where('environment_id', $preview->environment_id)->sole();
    $productionInstance = ServiceInstance::query()->where('environment_id', $production->id)->where('service_id', $web->id)->sole();

    expect($instance->deploy->startCommand)->toBe(['node', 'server.js', '--preview'])
        ->and($instance->scaling->replicas)->toBe(1)
        ->and($instance->scaling->placementLabels)->toBe([])
        ->and(Domain::query()->where('hostname', 'pr.example.com')->exists())->toBeFalse()
        ->and($productionInstance->deploy->startCommand)->not->toBe(['node', 'server.js', '--preview'])
        ->and($record->commit_sha)->toBe(PreviewFlow::SHA_PR_1)
        ->and(implode("\n", array_column($record->ignored, 'message')))->toContain('domains are ignored in previews');
});

test('a fork\'s file can\'t add services', function (): void {
    [, , , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['fork_policy' => ForkPolicy::Approval])->save();
    ConfigFiles::serve([PreviewFlow::SHA_PR_1 => (string) json_encode(['version' => 1, 'services' => [
        'web' => ['kind' => 'app'],
        'miner' => ['kind' => 'image', 'image' => 'evil/miner:latest'],
    ]])]);

    $preview = PreviewFlow::event($project, overrides: ['isFork' => true, 'authorPermission' => 'write']);

    expect($project->services()->where('slug', 'miner')->exists())->toBeFalse()
        ->and($preview->refresh()->status)->not->toBe(PreviewStatus::Failed);
});

test('an invalid file fails the preview with its first problem', function (): void {
    [, , , $project] = PreviewFlow::project();
    ConfigFiles::serve([PreviewFlow::SHA_PR_1 => "{\n  \"version\": 1,\n  \"services\": { \"web\": { \"kind\": \"app\", \"scaling\": { \"replicas\": -1 } } }\n}"]);

    $preview = PreviewFlow::event($project)->refresh();

    expect($preview->status)->toBe(PreviewStatus::Failed)
        ->and($preview->status_reason)->toContain('launchroom.json has a problem: "replicas" should be at least 0. (line 3')
        ->and(EnvironmentConfigFile::query()->where('environment_id', $preview->environment_id)->sole()->status)->toBe(ConfigFileStatus::Invalid);
});
