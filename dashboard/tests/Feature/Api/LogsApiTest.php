<?php

declare(strict_types=1);

use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('deployment logs page by cursor', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->web);
    $world->logs($deployment, ['Installing', 'Building'], 1, 'build');
    $world->logs($deployment, ['Started'], 2);
    $plain = Api::token($world->member(TeamRole::Viewer), $world->team, [ApiAbility::Read]);

    $page = Api::get("deployments/{$deployment->public_id}/logs", $plain)->assertOk();
    $texts = array_column($page->json('data'), 'text');

    expect($texts)->toContain('Installing', 'Started')
        ->and($page->json('meta.finished'))->toBeTrue()
        ->and($page->json('meta.hasMore'))->toBeFalse();

    Api::get("deployments/{$deployment->public_id}/logs?after=".$page->json('meta.cursor'), $plain)
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('the deployment log stream sends lines, the status and ends when finished', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->web);
    $world->logs($deployment, ['hello from the build'], 1, 'build');
    $plain = Api::token($world->member(TeamRole::Viewer), $world->team, [ApiAbility::Read]);

    $response = Api::get("deployments/{$deployment->public_id}/logs/stream", $plain)->assertOk();
    $body = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($body)->toContain("event: lines\n")
        ->and($body)->toContain('hello from the build')
        ->and($body)->toContain("event: status\ndata: {\"status\":\"active\",\"finished\":true}")
        ->and($body)->toContain("event: end\n");

    // Resuming after the last id sends no old lines again.
    preg_match('/^id: (\d+)$/m', $body, $match);
    $resumed = Api::call('GET', "deployments/{$deployment->public_id}/logs/stream", $plain, [], ['Last-Event-ID' => $match[1]])->streamedContent();
    expect($resumed)->not->toContain('hello from the build');
});

test('environment logs use the explorer query and the stream reconnects at its limit', function (): void {
    config(['api.streams.max_seconds' => 0]);
    $world = DeploymentUi::make();
    $world->logs($world->latest($world->web), ['explorer line'], 1, 'build');
    $plain = Api::token($world->member(TeamRole::Viewer), $world->team, [ApiAbility::Read]);
    $env = $world->production->public_id;

    $page = Api::get("environments/{$env}/logs?range=24h&source=all", $plain)->assertOk();
    expect(array_column($page->json('data'), 'message'))->toContain('explorer line')
        ->and($page->json('meta.cursor'))->toBeInt();

    Api::get("environments/{$env}/logs?range=1y", $plain)->assertUnprocessable();

    $stream = Api::get("environments/{$env}/logs/stream?service={$world->web->public_id}", $plain)->assertOk()->streamedContent();
    expect($stream)->toContain('event: reconnect');
});
