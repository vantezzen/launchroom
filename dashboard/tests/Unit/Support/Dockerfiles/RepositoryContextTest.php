<?php

declare(strict_types=1);

use App\Support\Dockerfiles\RepositoryContext;

test('the deny-list covers every path component', function (string $path, bool $denied): void {
    expect(RepositoryContext::isDeniedPath($path))->toBe($denied);
})->with([
    ['package.json', false],
    ['src/server.ts', false],
    ['.env', true],
    ['.env.production', true],
    ['apps/web/.env.local', true],
    ['certs/server.pem', true],
    ['deploy/id_ed25519.pub', true],
    ['.npmrc', true],
    ['.yarnrc.yml', true],
    ['config/credentials.json', true],
    ['secrets/db.txt', true],
    ['docker-compose.yml', true],
    ['compose.prod.yaml', true],
    ['infra/prod.tfvars', true],
    ['../escape', true],
    ['/etc/passwd', true],
    ['a//b', true],
]);

test('contents are scrubbed of credentials and the environment’s secret values', function (): void {
    $text = <<<'TXT'
        DATABASE_URL=postgres://app:s3cr3tpass@db:5432/app
        "apiKey": "abcd1234efgh"
        STRIPE=sk_live_abcdefghijklmnop
        token: __GITHUB_TOKEN__
        anthropic sk-ant-api03-aaaaaaaaaaaaaaaaaaaaaaaa
        -----BEGIN OPENSSH PRIVATE KEY-----
        abc
        -----END OPENSSH PRIVATE KEY-----
        the value correct-horse-battery-staple appears here, and base64 Y29ycmVjdC1ob3JzZS1iYXR0ZXJ5LXN0YXBsZQ==
        "author": "Jane", "js-tokens": "^4.0.0"
        TXT;
    // Built at runtime so the source holds no token-shaped literal.
    $text = str_replace('__GITHUB_TOKEN__', 'ghp_'.str_repeat('a', 36), $text);

    $scrubbed = RepositoryContext::scrub($text, ['correct-horse-battery-staple']);

    expect($scrubbed)
        ->not->toContain('s3cr3tpass')
        ->not->toContain('abcd1234efgh')
        ->not->toContain('sk_live_abcdefghijklmnop')
        ->not->toContain('ghp_aaaa')
        ->not->toContain('sk-ant-api03')
        ->not->toContain('abc'."\n".'-----END')
        ->not->toContain('correct-horse-battery-staple')
        ->not->toContain('Y29ycmVjdC1ob3JzZS1iYXR0ZXJ5LXN0YXBsZQ')
        ->toContain('"author": "Jane"')
        ->toContain('"js-tokens": "^4.0.0"');
});

test('sanitize drops denied paths, binary and oversized files and enforces totals', function (): void {
    $files = [
        ['path' => 'package.json', 'content' => '{"name":"x"}', 'truncated' => false],
        ['path' => '.env', 'content' => 'X=1', 'truncated' => false],
        ['path' => 'logo.txt', 'content' => "bin\0ary", 'truncated' => false],
        ['path' => 'big.md', 'content' => str_repeat('a', 40000), 'truncated' => false],
    ];

    for ($i = 0; $i < 60; $i++) {
        $files[] = ['path' => "f{$i}.txt", 'content' => str_repeat('b', 8000), 'truncated' => false];
    }

    $context = RepositoryContext::sanitize(['tree' => ['package.json', '.env', 'src/a.ts'], 'treeTruncated' => false, 'files' => $files]);

    expect($context['tree'])->toBe(['package.json', 'src/a.ts'])
        ->and(array_column($context['files'], 'path'))->not->toContain('.env')
        ->and(array_column($context['files'], 'path'))->not->toContain('logo.txt')
        ->and(count($context['files']))->toBeLessThanOrEqual(RepositoryContext::MAX_FILES)
        ->and(array_sum(array_map(static fn (array $f): int => strlen($f['content']), $context['files'])))->toBeLessThanOrEqual(RepositoryContext::MAX_TOTAL_BYTES);

    $big = array_values(array_filter($context['files'], static fn (array $f): bool => $f['path'] === 'big.md'))[0];
    expect(strlen($big['content']))->toBe(RepositoryContext::MAX_FILE_BYTES)->and($big['truncated'])->toBeTrue();
});
