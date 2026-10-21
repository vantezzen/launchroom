<?php

declare(strict_types=1);

use App\Services\Mesh\Exceptions\HeadscaleUnavailable;
use App\Services\Mesh\HeadscaleClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHeadscale;

test('the client creates users and single-use tagged pre-auth keys with its bearer key', function (): void {
    $fake = FakeHeadscale::install();
    $client = new HeadscaleClient(FakeHeadscale::URL, FakeHeadscale::BOOTSTRAP_KEY);

    $user = $client->ensureUser('launchroom');
    expect($client->ensureUser('launchroom'))->toBe($user)
        ->and($fake->users)->toHaveCount(1);

    $key = $client->createPreAuthKey($user, ['tag:lr-runtime'], now()->addHour());

    expect($key->key)->toStartWith('hskey-auth-')
        ->and($fake->preAuthKeys[$key->id]['tags'])->toBe(['tag:lr-runtime'])
        ->and(print_r($key, true))->not->toContain($key->key);

    Http::assertSent(fn (Request $r): bool => $r->url() === FakeHeadscale::URL.'/api/v1/preauthkey'
        && $r->hasHeader('Authorization', 'Bearer '.FakeHeadscale::BOOTSTRAP_KEY)
        && $r['reusable'] === false && $r['ephemeral'] === false && $r['user'] === $user);
});

test('nodes, tags, deletion and policy go through the API', function (): void {
    $fake = FakeHeadscale::install();
    $client = new HeadscaleClient(FakeHeadscale::URL, FakeHeadscale::BOOTSTRAP_KEY);
    $id = $fake->addNode('100.64.0.2', ['tag:lr-runtime']);

    $nodes = $client->nodes();
    expect($nodes)->toHaveCount(1)
        ->and($nodes[0]->ipAddresses)->toBe(['100.64.0.2'])
        ->and($nodes[0]->tags)->toBe(['tag:lr-runtime']);

    $client->setTags($id, ['tag:lr-build', 'tag:lr-runtime']);
    $client->setPolicy('{"acls":[]}');
    $client->deleteNode($id);
    $client->deleteNode($id); // already gone: fine

    expect($fake->nodes)->toBe([])
        ->and($fake->policy)->toBe('{"acls":[]}');
});

test('errors never carry keys and say what failed', function (): void {
    FakeHeadscale::install();
    $client = new HeadscaleClient(FakeHeadscale::URL, 'hskey-api-WRONGWRONG00-secretsecretsecret');

    expect(fn (): array => $client->nodes())->toThrow(function (HeadscaleUnavailable $e): void {
        expect($e->getMessage())->toContain('list nodes')->toContain('401')->not->toContain('secretsecret');
    });
});

test('an unreachable Headscale is reported, not leaked', function (): void {
    Http::fake([FakeHeadscale::URL.'/*' => Http::failedConnection()]);
    $client = new HeadscaleClient(FakeHeadscale::URL, FakeHeadscale::BOOTSTRAP_KEY);

    expect(fn (): string => $client->ensureUser('launchroom'))->toThrow(HeadscaleUnavailable::class, 'Headscale is not reachable');
});
