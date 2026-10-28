<?php

declare(strict_types=1);

use App\Support\Git\PublicRepositoryUrl;
use App\Support\Git\RefAdvertisement;

/*
| Pasted repository URLs on New project (docs/19 §4.3): grammar only, HTTPS
| only, forge browser URLs cut down to the repository.
*/

test('public repository URLs are normalised to a clone URL', function (string $input, string $clone, string $canonical, string $provider, ?string $branch): void {
    $url = PublicRepositoryUrl::parse($input);

    expect($url->cloneUrl())->toBe($clone)
        ->and($url->canonical())->toBe($canonical)
        ->and($url->provider)->toBe($provider)
        ->and($url->branch)->toBe($branch);
})->with([
    'scheme-less' => ['github.com/umami-software/umami', 'https://github.com/umami-software/umami.git', 'https://github.com/umami-software/umami', 'github', null],
    'with .git and slash' => ['https://github.com/Umami-Software/Umami.git/', 'https://github.com/Umami-Software/Umami.git', 'https://github.com/umami-software/umami', 'github', null],
    'www and case' => ['HTTPS://WWW.GitHub.com/acme/app', 'https://github.com/acme/app.git', 'https://github.com/acme/app', 'github', null],
    'browser URL with a branch' => ['https://github.com/acme/app/tree/dev', 'https://github.com/acme/app.git', 'https://github.com/acme/app', 'github', 'dev'],
    'browser URL into a file' => ['https://github.com/acme/app/blob/main/README.md', 'https://github.com/acme/app.git', 'https://github.com/acme/app', 'github', null],
    'GitLab subgroup' => ['https://gitlab.com/group/sub/app/-/tree/main', 'https://gitlab.com/group/sub/app.git', 'https://gitlab.com/group/sub/app', 'gitlab', 'main'],
    'Codeberg' => ['codeberg.org/forgejo/forgejo/src/branch/forgejo', 'https://codeberg.org/forgejo/forgejo.git', 'https://codeberg.org/forgejo/forgejo', 'codeberg', 'forgejo'],
    'own server as typed' => ['https://git.example.org/srv/app', 'https://git.example.org/srv/app', 'https://git.example.org/srv/app', 'git', null],
    'own server with .git' => ['https://git.example.org/app.git', 'https://git.example.org/app.git', 'https://git.example.org/app', 'git', null],
]);

test('anything but a public HTTPS repository address is refused', function (string $input): void {
    expect(PublicRepositoryUrl::tryParse($input))->toBeNull();
})->with([
    'empty' => [''],
    'plain http' => ['http://github.com/acme/app'],
    'ssh' => ['git@github.com:acme/app.git'],
    'ssh scheme' => ['ssh://git@github.com/acme/app.git'],
    'credentials' => ['https://user:token@github.com/acme/app'],
    'query' => ['https://github.com/acme/app?tab=readme'],
    'fragment' => ['https://github.com/acme/app#readme'],
    'owner only' => ['https://github.com/acme'],
    'no dot in host' => ['https://localhost/app'],
    'ip-like nonsense' => ['https://exa mple.com/app'],
    'dot segments' => ['https://git.example.org/a/../b'],
    'file scheme' => ['file:///etc/passwd'],
    'backslash' => ['https://github.com\\acme/app'],
]);

test('the user hears why a URL is refused', function (): void {
    expect(fn (): PublicRepositoryUrl => PublicRepositoryUrl::parse('http://github.com/acme/app'))->toThrow(InvalidArgumentException::class, 'https://')
        ->and(fn (): PublicRepositoryUrl => PublicRepositoryUrl::parse('git@github.com:acme/app.git'))->toThrow(InvalidArgumentException::class, 'deploy key');
});

function lsPkt(string $line): string
{
    return sprintf('%04x', strlen($line) + 4).$line;
}

test('a protocol v2 ls-refs answer gives the branches and where HEAD points', function (): void {
    $a = str_repeat('a', 40);
    $b = str_repeat('b', 40);
    $body = lsPkt("{$a} HEAD symref-target:refs/heads/master\n").lsPkt("{$a} refs/heads/master\n").lsPkt("{$b} refs/heads/dev\n").lsPkt("zzz refs/heads/bad\n").'0000';

    expect(RefAdvertisement::parseLsRefs($body))->toBe([
        'refs' => ['HEAD' => $a, 'refs/heads/master' => $a, 'refs/heads/dev' => $b],
        'head' => 'master',
    ]);
});

test('the v0 advertisement names HEAD through its symref capability', function (): void {
    $a = str_repeat('a', 40);
    $body = lsPkt("# service=git-upload-pack\n").'0000'.lsPkt("{$a} HEAD\0multi_ack symref=HEAD:refs/heads/trunk agent=git/2\n").lsPkt("{$a} refs/heads/trunk\n").'0000';

    expect(RefAdvertisement::headSymref($body))->toBe('trunk')
        ->and(RefAdvertisement::parse($body))->toHaveKey('refs/heads/trunk');
});
