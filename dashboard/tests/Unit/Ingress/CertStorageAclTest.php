<?php

declare(strict_types=1);

use App\Services\Ingress\CertStorage;

// 2026-10 review M3: the edges' Valkey user lives on the dashboard's main
// Valkey (sessions, cache, queues, Reverb), so it may hold only the exact
// commands the storage module, its lock library and launchroomd's mirror send.
test('the lr-caddy ACL grants explicit commands only, never a category', function (): void {
    $args = CertStorage::setUserArguments('x');
    $grants = array_values(array_filter($args, fn (string $a): bool => str_starts_with($a, '+')));

    expect($args)->toContain('-@all')
        ->and(array_search('-@all', $args, true))->toBeLessThan(array_search($grants[0], $args, true))
        ->and(array_filter($grants, fn (string $g): bool => str_contains($g, '@')))->toBe([]);
});

test('the lr-caddy ACL can not pause, kill, list or reconfigure the dashboard’s Valkey', function (): void {
    $grants = explode(' ', CertStorage::COMMANDS);

    foreach (['+client', '+client|kill', '+client|pause', '+client|list', '+client|no-evict', '+client|setname', '+select', '+config', '+keys', '+flushall', '+flushdb', '+monitor', '+subscribe', '+publish', '+acl', '+debug', '+shutdown', '+script|load', '+script', '+unlink'] as $forbidden) {
        expect($grants)->not->toContain($forbidden);
    }

    // What the clients need (go-redis handshake, caddy-storage-redis, the
    // redislock scripts incl. GETRANGE, launchroomd's mirror).
    foreach (['+hello', '+auth', '+ping', '+get', '+set', '+del', '+exists', '+zadd', '+zrem', '+zrange', '+scan', '+type', '+evalsha', '+eval', '+getrange', '+pexpire', '+pttl'] as $needed) {
        expect($grants)->toContain($needed);
    }
});
