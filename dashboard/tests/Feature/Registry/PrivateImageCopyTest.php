<?php

declare(strict_types=1);

use App\Actions\Deployments\AdvanceDeployment;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Registry\MirrorPrivateImage;
use App\Contracts\DnsResolver;
use App\Data\Audit\AuditActor;
use App\Enums\DeploymentStatus;
use App\Enums\Registry\RegistryCopyStatus;
use App\Enums\Registry\RegistryCredentialStatus;
use App\Models\Deployment;
use App\Models\Registry\RegistryCredential;
use App\Models\Registry\RegistryImageCopy;
use App\Models\Team;
use App\Services\Crypto\SealedColumns;
use App\Services\Registry\RegistryTokenKeyFile;
use App\Support\Images\ImageReference;
use App\Support\PublicId;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Projects;

/*
| Private images are copied by the dashboard (D91): the team's token only
| ever goes to the upstream registry; Zot receives the image by digest
| under the team's mirror path.
*/

const COPY_SECRET = 'glpat-copy-secret-0001';

beforeEach(function (): void {
    Projects::useTestKek();
    app()->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $hostname): array
        {
            return ['93.184.216.34'];
        }
    });

    $this->keyDir = sys_get_temp_dir().'/launchroom-registry-'.bin2hex(random_bytes(6));
    config([
        'launchroom.registry.token_key_path' => $this->keyDir.'/registry-token.es256.pem',
        'launchroom.registry.token_public_key_path' => $this->keyDir.'/public/registry-token.pub.pem',
        'launchroom.registry_lookup.url' => 'http://zot:5000',
    ]);
    RegistryTokenKeyFile::create(config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path'));

    $this->team = Team::factory()->create();
    $credential = new RegistryCredential(['team_id' => $this->team->id, 'host' => 'registry.example.com', 'username' => 'deploy']);
    $credential->public_id = PublicId::generate('rgc');
    $sealed = resolve(SealedColumns::class);
    $sealed->seal($credential, 'secret', COPY_SECRET, $sealed->newKey($credential));
    $credential->save();
    $this->credential = $credential;
    $this->target = 'mirror/'.strtolower($this->team->public_id).'/registry.example.com/acme/api';

    // Two platforms; the amd64 layer is already in Zot.
    $this->blobs = [];
    $children = [];

    foreach (['amd64', 'arm64'] as $arch) {
        $config = json_encode(['architecture' => $arch, 'os' => 'linux']);
        $layer = str_repeat($arch, 1000);
        $this->blobs['sha256:'.hash('sha256', $config)] = $config;
        $this->blobs['sha256:'.hash('sha256', $layer)] = $layer;
        $manifest = json_encode([
            'schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => 'sha256:'.hash('sha256', $config), 'size' => strlen($config)],
            'layers' => [['mediaType' => 'application/vnd.oci.image.layer.v1.tar+gzip', 'digest' => 'sha256:'.hash('sha256', $layer), 'size' => strlen($layer)]],
        ]);
        $children['sha256:'.hash('sha256', $manifest)] = $manifest;
    }

    $index = json_encode(['schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.index.v1+json', 'manifests' => array_map(
        static fn (string $digest): array => ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => $digest, 'size' => 1],
        array_keys($children),
    )]);
    $this->indexDigest = 'sha256:'.hash('sha256', $index);
    $this->manifests = [...$children, $this->indexDigest => $index, 'v1' => $index];
    $this->existing = ['sha256:'.hash('sha256', str_repeat('amd64', 1000))];
    $this->tampered = null;
    $this->upstreamStatus = null;
    $this->stored = ['blobs' => [], 'manifests' => []];
    $this->downloaded = [];
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

function fakeRegistries(object $test): void
{
    Http::fake(function (Request $request) use ($test) {
        $url = $request->url();
        $auth = $request->header('Authorization')[0] ?? '';

        if (str_starts_with($url, 'https://auth.example.com/token')) {
            expect($url)->toContain('scope=repository%3Aacme%2Fapi%3Apull');

            return $auth === 'Basic '.base64_encode('deploy:'.COPY_SECRET) ? Http::response(['access_token' => 'up-token']) : Http::response('', 401);
        }

        if (str_starts_with($url, 'https://registry.example.com/')) {
            expect($url)->not->toContain(COPY_SECRET);

            if ($auth !== 'Bearer up-token') {
                return Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.example.com/token",service="registry.example.com",scope="repository:acme/api:pull"']);
            }

            if ($test->upstreamStatus !== null) {
                return Http::response('', $test->upstreamStatus);
            }

            if (preg_match('#/v2/acme/api/manifests/(.+)$#', $url, $m) === 1) {
                $ref = rawurldecode($m[1]);
                $body = $test->manifests[$ref] ?? null;

                return $body === null ? Http::response('', 404) : Http::response($body, 200, ['Content-Type' => json_decode($body, true)['mediaType']]);
            }

            if (preg_match('#/v2/acme/api/blobs/(sha256:[a-f0-9]{64})$#', $url, $m) === 1) {
                $test->downloaded[] = $m[1];

                return Http::response($m[1] === $test->tampered ? 'tampered' : $test->blobs[$m[1]]);
            }

            return Http::response('', 404);
        }

        if (str_starts_with($url, 'http://zot:5000/v2/'.$test->target.'/')) {
            expect($auth)->toStartWith('Bearer ')->not->toContain(COPY_SECRET);
            $path = substr($url, strlen('http://zot:5000/v2/'.$test->target));

            if ($request->method() === 'HEAD' && preg_match('#^/blobs/(sha256:[a-f0-9]{64})$#', $path, $m) === 1) {
                return Http::response('', in_array($m[1], [...$test->existing, ...array_keys($test->stored['blobs'])], true) ? 200 : 404);
            }

            if ($request->method() === 'POST' && $path === '/blobs/uploads/') {
                return Http::response('', 202, ['Location' => '/v2/'.$test->target.'/blobs/uploads/u1?state=x']);
            }

            if ($request->method() === 'PUT' && preg_match('#digest=(sha256%3A[a-f0-9]{64})#', $url, $m) === 1) {
                $digest = rawurldecode($m[1]);
                expect('sha256:'.hash('sha256', $request->body()))->toBe($digest);
                $test->stored['blobs'][$digest] = true;

                return Http::response('', 201);
            }

            if ($request->method() === 'PUT' && preg_match('#^/manifests/(sha256:[a-f0-9]{64})$#', $path, $m) === 1) {
                expect('sha256:'.hash('sha256', $request->body()))->toBe($m[1]);
                $test->stored['manifests'][] = $m[1];

                return Http::response('', 201);
            }
        }

        return Http::response('', 500);
    });
}

test('a private image is copied with every platform into the team mirror, then deploys from there', function (): void {
    fakeRegistries($this);
    $mirror = resolve(MirrorPrivateImage::class);
    $reference = ImageReference::tryParse('registry.example.com/acme/api:v1');

    expect($mirror->handle($this->team->id, $reference)?->isPending())->toBeTrue();

    $copy = RegistryImageCopy::query()->sole();
    expect($copy->status)->toBe(RegistryCopyStatus::Succeeded)
        ->and($copy->digest)->toBe($this->indexDigest)
        // Both children before the index; the existing amd64 layer was never downloaded.
        ->and($this->stored['manifests'])->toHaveCount(3)
        ->and(end($this->stored['manifests']))->toBe($this->indexDigest)
        ->and($this->downloaded)->not->toContain($this->existing[0])
        ->and(count($this->stored['blobs']))->toBe(3)
        ->and($this->credential->refresh()->status)->toBe(RegistryCredentialStatus::Ok);

    $result = $mirror->handle($this->team->id, $reference);
    expect($result?->isReady())->toBeTrue()
        ->and($result?->image)->toBe('127.0.0.1:5000/'.$this->target.'@'.$this->indexDigest)
        ->and(ImageReference::isDigestPinned((string) $result?->image))->toBeTrue();
});

test('images on hosts without sign-in details keep using the public mirror', function (): void {
    expect(resolve(MirrorPrivateImage::class)->handle($this->team->id, ImageReference::tryParse('ghcr.io/acme/api:v1')))->toBeNull()
        ->and(resolve(MirrorPrivateImage::class)->handle(Team::factory()->create()->id, ImageReference::tryParse('registry.example.com/acme/api:v1')))->toBeNull();
});

test('a layer that doesn’t match its digest fails the copy and nothing is stored for it', function (): void {
    fakeRegistries($this);
    $this->tampered = 'sha256:'.hash('sha256', str_repeat('arm64', 1000));

    resolve(MirrorPrivateImage::class)->handle($this->team->id, ImageReference::tryParse('registry.example.com/acme/api:v1'));

    $copy = RegistryImageCopy::query()->sole();
    expect($copy->status)->toBe(RegistryCopyStatus::Failed)
        ->and($copy->message)->toContain('didn’t match its digest')
        ->and($this->stored['blobs'])->not->toHaveKey($this->tampered)
        ->and(resolve(MirrorPrivateImage::class)->handle($this->team->id, ImageReference::tryParse('registry.example.com/acme/api:v1'))?->isFailed())->toBeTrue();
});

test('registry answers become messages people can act on, without the token', function (?int $status, string $reference, string $message, RegistryCredentialStatus $credential): void {
    fakeRegistries($this);
    $this->upstreamStatus = $status;

    if ($status === null) {
        resolve(SealedColumns::class)->seal($this->credential, 'secret', 'wrong-token', resolve(SealedColumns::class)->newKey($this->credential));
        $this->credential->save();
    }

    $result = resolve(MirrorPrivateImage::class)->handle($this->team->id, ImageReference::tryParse('registry.example.com/acme/api:'.$reference));
    expect($result?->isPending())->toBeTrue();

    $copy = RegistryImageCopy::query()->sole();
    expect($copy->status)->toBe(RegistryCopyStatus::Failed)
        ->and($copy->message)->toContain($message)
        ->and($copy->message)->not->toContain(COPY_SECRET)
        ->and($this->credential->refresh()->status)->toBe($credential);
})->with([
    'refused sign-in' => [null, 'v1', 'refused the sign-in for deploy on registry.example.com', RegistryCredentialStatus::Failed],
    'not found' => [404, 'v1', 'registry.example.com/acme/api:v1 was not found', RegistryCredentialStatus::Unverified],
    'rate limited' => [429, 'v1', 'limiting requests', RegistryCredentialStatus::Unverified],
    'no access' => [403, 'v1', 'deploy has no access to registry.example.com/acme/api:v1', RegistryCredentialStatus::Unverified],
]);

test('a failed copy is retried when a later deploy asks again', function (): void {
    fakeRegistries($this);
    $this->upstreamStatus = 429;
    $mirror = resolve(MirrorPrivateImage::class);
    $reference = ImageReference::tryParse('registry.example.com/acme/api:v1');
    $mirror->handle($this->team->id, $reference);

    $this->upstreamStatus = null;
    $this->travel(2)->minutes();

    expect($mirror->handle($this->team->id, $reference)?->isPending())->toBeTrue()
        ->and(RegistryImageCopy::query()->sole()->status)->toBe(RegistryCopyStatus::Succeeded);
});

test('an image service on a host with sign-in details waits for the team copy, then deploys from it', function (): void {
    fakeRegistries($this);
    Projects::server($this->team);
    $project = Projects::create($this->team);
    Projects::image($project, 'api', 'registry.example.com/acme/api:v1', 3000);

    // The public mirror is never asked (fakeRegistries answers it with 500).
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    $deployment = Deployment::query()->latest('id')->firstOrFail();
    expect($deployment->image)->toBeNull()
        ->and($deployment->status)->not->toBe(DeploymentStatus::Failed)
        ->and(RegistryImageCopy::query()->sole()->status)->toBe(RegistryCopyStatus::Succeeded);

    resolve(AdvanceDeployment::class)->handle($deployment->id);

    expect($deployment->refresh()->image)->toBe('127.0.0.1:5000/'.$this->target.'@'.$this->indexDigest)
        ->and($deployment->status)->not->toBe(DeploymentStatus::Failed);
});
