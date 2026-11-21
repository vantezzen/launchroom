<?php

declare(strict_types=1);

use App\Enums\AppUpdates\UpdateKind;
use App\Support\Images\CuratedPin;
use App\Support\Images\ReleaseCandidate;
use App\Support\Images\ReleaseCandidates;

/*
 * Which newer tags to offer (docs/04 §14.1): the newest patch, minor and
 * major of the running tag's channel, plus newer template pins.
 */

/**
 * @param  list<ReleaseCandidate>  $candidates
 * @return array<string, string>
 */
function offered(array $candidates): array
{
    $out = [];

    foreach ($candidates as $candidate) {
        $out[$candidate->tag] = $candidate->kind->value;
    }

    return $out;
}

test('the newest patch, minor and major of the channel are offered, biggest first', function (): void {
    $tags = ['v4.1.0', 'v4.1.1', 'v4.2.0', 'v4.10.3', 'v5.0.0', 'v5.0.2', 'v6.0.0-rc.1', 'latest', '4.1.2', 'v4.1.3-alpine'];

    expect(offered(ReleaseCandidates::for('v4.1.0', $tags)))->toBe([
        'v5.0.2' => 'major',
        'v4.10.3' => 'minor',
        'v4.1.1' => 'patch',
    ]);
});

test('a variant only gets newer tags of its own family', function (): void {
    $tags = ['6.71.0-alpine', '6.72.0-alpine', '6.73.0', '7.0.0-alpine3.21', '6.72.1-alpine'];

    expect(offered(ReleaseCandidates::for('6.71.0-alpine', $tags)))->toBe([
        '6.72.1-alpine' => 'minor',
    ]);
});

test('pre-releases are offered only to a pre-release', function (): void {
    expect(ReleaseCandidates::for('2.0.0', ['2.1.0-beta1']))->toBe([])
        ->and(offered(ReleaseCandidates::for('2.1.0-beta1', ['2.1.0-beta2'])))->toBe(['2.1.0-beta2' => 'patch']);
});

test('a moving word has no version candidates', function (): void {
    expect(ReleaseCandidates::for('latest', ['1.0.0', '2.0.0']))->toBe([]);
});

test('a short tag is offered the next one of its length', function (): void {
    expect(offered(ReleaseCandidates::for('6', ['5', '6', '7', '7.1.0'])))->toBe(['7' => 'major']);
});

test('a newer template pin joins as tested, an older one does not', function (): void {
    $pin = new CuratedPin('docker.io', 'listmonk/listmonk', 'v6.3.0', 'sha256:'.str_repeat('a', 64), 'listmonk', 'Listmonk');
    $old = new CuratedPin('docker.io', 'listmonk/listmonk', 'v6.1.0', 'sha256:'.str_repeat('b', 64), 'listmonk', 'Listmonk');

    $candidates = ReleaseCandidates::for('v6.2.0', ['v6.2.0', 'v6.3.0', 'v6.3.1'], [$pin, $old]);

    expect(offered($candidates))->toBe(['v6.3.1' => 'minor', 'v6.3.0' => 'minor'])
        ->and($candidates[1]->curatedBy)->toBe('Listmonk')
        ->and($candidates[1]->digest)->toBe('sha256:'.str_repeat('a', 64))
        ->and($candidates[0]->kind)->toBe(UpdateKind::Minor);
});

test('without a registry answer a template pin is still offered', function (): void {
    $pin = new CuratedPin('docker.io', 'listmonk/listmonk', 'v6.3.0', 'sha256:'.str_repeat('a', 64), 'listmonk', 'Listmonk');

    expect(offered(ReleaseCandidates::for('v6.2.0', [], [$pin])))->toBe(['v6.3.0' => 'minor']);
});
