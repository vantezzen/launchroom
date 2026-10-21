<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Contracts\ImageDigestResolver;
use App\Data\Audit\AuditActor;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Exceptions\ImageResolutionFailed;
use App\Models\Deployment;
use App\Models\Team;
use App\Services\Registry\RegistryTokenIssuer;
use App\Services\Registry\RegistryTokenKeyFile;
use App\Support\Images\ImageReference;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Projects;

const IR_DIGEST = 'sha256:7c3f0d5e2a1b4c6d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d';

beforeEach(function (): void {
    Projects::useTestKek();
    $this->keyDir = sys_get_temp_dir().'/launchroom-registry-'.bin2hex(random_bytes(6));
    config([
        'launchroom.registry.token_key_path' => $this->keyDir.'/registry-token.es256.pem',
        'launchroom.registry.token_public_key_path' => $this->keyDir.'/public/registry-token.pub.pem',
        'launchroom.registry_lookup.url' => 'http://zot:5000',
    ]);
    RegistryTokenKeyFile::create(config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path'));
});

afterEach(function (): void {
    foreach ([config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path')] as $path) {
        if (is_file($path)) {
            chmod($path, 0600);
            unlink($path);
        }
    }

    @rmdir($this->keyDir.'/public');
    @rmdir($this->keyDir);
});

test('tags resolve through the mirror with a narrow pull token and multi-arch accept headers', function (): void {
    Http::fake(['http://zot:5000/*' => Http::response('', 200, ['Docker-Content-Digest' => IR_DIGEST])]);

    $resolved = resolve(ImageDigestResolver::class)->resolve(ImageReference::tryParse('ghcr.io/umami-software/umami:postgresql-latest'));
    resolve(ImageDigestResolver::class)->resolve(ImageReference::tryParse('ghcr.io/umami-software/umami:postgresql-latest'));

    expect($resolved->digest)->toBe(IR_DIGEST)
        ->and($resolved->mirrored('127.0.0.1:5000/mirror'))->toBe('127.0.0.1:5000/mirror/ghcr.io/umami-software/umami@'.IR_DIGEST);

    Http::assertSentCount(1); // cached
    Http::assertSent(function (Request $request): bool {
        $token = substr($request->header('Authorization')[0] ?? '', strlen('Bearer '));
        $verified = resolve(RegistryTokenIssuer::class)->verify($token);

        return $request->method() === 'HEAD'
            && $request->url() === 'http://zot:5000/v2/mirror/ghcr.io/umami-software/umami/manifests/postgresql-latest'
            && str_contains($request->header('Accept')[0] ?? '', 'application/vnd.oci.image.index.v1+json')
            && str_contains($request->header('Accept')[0] ?? '', 'application/vnd.docker.distribution.manifest.list.v2+json')
            && $verified !== null
            && count($verified->scopes) === 1
            && $verified->scopes[0]->repository === 'mirror/ghcr.io/umami-software/umami'
            && $verified->scopes[0]->actions === ['pull'];
    });
});

test('an image service with a tag deploys digest-pinned through the mirror', function (): void {
    Http::fake(['http://zot:5000/*' => Http::response('', 200, ['Docker-Content-Digest' => IR_DIGEST])]);
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team);
    Projects::image($project, 'umami', 'ghcr.io/umami-software/umami:postgresql-latest', 3000);

    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    $deployment = Deployment::query()->latest('id')->firstOrFail();
    expect($deployment->status)->toBe(DeploymentStatus::HealthChecking)
        ->and($deployment->image)->toBe('127.0.0.1:5000/mirror/ghcr.io/umami-software/umami@'.IR_DIGEST);
});

test('tags are resolved before the deployment row is locked', function (): void {
    $levels = [];
    Http::fake(['http://zot:5000/*' => function () use (&$levels): PromiseInterface {
        $levels[] = DB::transactionLevel();

        return Http::response('', 200, ['Docker-Content-Digest' => IR_DIGEST]);
    }]);
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team);
    Projects::image($project, 'umami', 'ghcr.io/umami-software/umami:postgresql-latest', 3000);
    $outside = DB::transactionLevel();

    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    expect(Deployment::query()->latest('id')->firstOrFail()->image)->toBe('127.0.0.1:5000/mirror/ghcr.io/umami-software/umami@'.IR_DIGEST)
        ->and($levels)->toBe([$outside]);
});

test('unknown images and registry trouble fail with a config error and a hint', function (int $status, string $message): void {
    Http::fake(['http://zot:5000/*' => Http::response('', $status)]);
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team);
    Projects::image($project, 'umami', 'docker.io/nope/nope:1', 3000);

    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    $deployment = Deployment::query()->latest('id')->firstOrFail();
    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_class)->toBe(DeploymentFailureClass::ConfigError)
        ->and($deployment->failure_message)->toContain($message);
})->with([
    'not found' => [404, 'docker.io/nope/nope:1 was not found'],
    'registry error' => [502, 'HTTP 502'],
]);

test('a digest-pinned reference needs no lookup', function (): void {
    Http::fake();
    $reference = ImageReference::tryParse('postgres@'.IR_DIGEST);

    expect(resolve(ImageDigestResolver::class)->resolve($reference))->toBe($reference);
    Http::assertNothingSent();
});

test('a resolver without a reachable registry explains how to pin', function (): void {
    Http::fake(['http://zot:5000/*' => fn () => throw new ConnectionException('refused')]);

    resolve(ImageDigestResolver::class)->resolve(ImageReference::tryParse('nginx:1.27'));
})->throws(ImageResolutionFailed::class, 'Could not reach the image registry');
