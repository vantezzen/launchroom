<?php

declare(strict_types=1);

use App\Contracts\DnsResolver;
use App\Services\Git\Ssh\DeployKey;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\ResolvedTarget;
use App\Services\Http\UrlGuard;
use App\Support\Git\GitServerBranches;
use App\Support\Git\HostKey;
use App\Support\Git\ProviderAvatar;
use App\Support\Git\RefAdvertisement;
use App\Support\Git\SshUrl;

/*
| Grammar and parsing for the git providers (docs/19): SSH URLs, host keys,
| ref advertisements, provider avatars, the private-network override.
*/

test('SSH URLs normalize to one canonical form', function (string $input, ?string $canonical, ?string $display = null): void {
    $url = SshUrl::parse($input);

    expect($url?->toUrl())->toBe($canonical);

    if ($display !== null) {
        expect($url?->displayName())->toBe($display);
    }
})->with([
    'scp, home-relative' => ['git@github.com:acme/web.git', 'ssh://git@github.com/~/acme/web.git', 'github.com/acme/web'],
    'scp, absolute' => ['git@host.example.com:/srv/git/app.git', 'ssh://git@host.example.com/srv/git/app.git', 'host.example.com/srv/git/app'],
    'ssh with port' => ['ssh://git@Git.Example.com:2222/team/app.git', 'ssh://git@git.example.com:2222/team/app.git'],
    'ssh home-relative' => ['ssh://deploy@host.example.com/~/app.git', 'ssh://deploy@host.example.com/~/app.git'],
    'quote' => ["git@host.example.com:app'.git", null],
    'space' => ['git@host.example.com:my app.git', null],
    'dot-dot' => ['ssh://git@host.example.com/srv/../etc/passwd', null],
    'password' => ['ssh://git:pw@host.example.com/app.git', null],
    'no user' => ['ssh://host.example.com/app.git', null],
    'percent' => ['ssh://git@host.example.com/a%20b.git', null],
    'option injection' => ['git@-oProxyCommand=x:app.git', null],
    'https' => ['https://host.example.com/app.git', null],
]);

test('host keys are read from the blob and fingerprinted like OpenSSH', function (): void {
    // ssh-keygen -lf of this ed25519 key prints SHA256:<base64 of sha256(blob) without padding>.
    $public = str_repeat("\x01", 32);
    $blob = pack('N', 11).'ssh-ed25519'.pack('N', 32).$public;
    $key = HostKey::fromOpenSsh('rsa-sha2-512 '.base64_encode($blob).' comment');

    expect($key?->type)->toBe('ssh-ed25519')
        ->and($key?->fingerprint())->toBe('SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='))
        ->and(HostKey::fromBlob(base64_encode(pack('N', 7).'ssh-dss'.str_repeat("\0", 20))))->toBeNull()
        ->and(HostKey::fromBlob('not base64!'))->toBeNull()
        ->and(HostKey::fromArray(['type' => 'ssh-rsa', 'key' => base64_encode($blob)]))->toBeNull();
});

test('deploy keys are OpenSSH ed25519 public keys; build servers get only the 32-byte seed', function (): void {
    $key = DeployKey::generate('launchroom-abc');
    [$type, $blob, $comment] = explode(' ', $key['publicKey']);

    expect($type)->toBe('ssh-ed25519')
        ->and($comment)->toBe('launchroom-abc')
        ->and(HostKey::fromBlob($blob)?->type)->toBe('ssh-ed25519')
        ->and(strlen((string) base64_decode(DeployKey::seed($key['secretKey']), true)))->toBe(32)
        ->and(substr((string) base64_decode($blob, true), -32))->toBe(sodium_crypto_sign_publickey_from_secretkey($key['secretKey']));
});

test('ref advertisements parse from smart HTTP and from SSH, and completeness is detected', function (): void {
    $line = static fn (string $payload): string => sprintf('%04x', strlen($payload) + 4).$payload;
    $a = str_repeat('a', 40);
    $b = str_repeat('b', 40);
    $ssh = $line("{$a} HEAD\0multi_ack symref=HEAD:refs/heads/main\n").$line("{$a} refs/heads/main\n").$line("{$b} refs/heads/dev\n").'0000';
    $http = $line("# service=git-upload-pack\n").'0000'.$ssh;

    expect(RefAdvertisement::parse($ssh))->toBe(['HEAD' => $a, 'refs/heads/main' => $a, 'refs/heads/dev' => $b])
        ->and(RefAdvertisement::parse($http))->toBe(['HEAD' => $a, 'refs/heads/main' => $a, 'refs/heads/dev' => $b])
        ->and(RefAdvertisement::isComplete($ssh))->toBeTrue()
        ->and(RefAdvertisement::isComplete(substr($ssh, 0, -10)))->toBeFalse()
        ->and(RefAdvertisement::parse('zzzz garbage'))->toBe([])
        ->and(GitServerBranches::defaultBranch(RefAdvertisement::parse($ssh)))->toBe('main')
        ->and(GitServerBranches::defaultBranch(['refs/heads/trunk' => $a]))->toBe('trunk')
        ->and(GitServerBranches::defaultBranch([]))->toBeNull();
});

test('provider avatars stay on the connection’s origin or Gravatar', function (?string $url, ?string $expected): void {
    expect(ProviderAvatar::url($url, 'https://gitlab.example.com'))->toBe($expected);
})->with([
    'origin' => ['https://gitlab.example.com/uploads/-/system/user/avatar/7/avatar.png', 'https://gitlab.example.com/uploads/-/system/user/avatar/7/avatar.png'],
    'gravatar' => ['https://secure.gravatar.com/avatar/abc123?s=80&d=identicon', 'https://secure.gravatar.com/avatar/abc123?s=80&d=identicon'],
    'other host' => ['https://evil.example.net/a.png', null],
    'http' => ['http://gitlab.example.com/a.png', null],
    'credentials' => ['https://user:pw@gitlab.example.com/a.png', null],
    'other port' => ['https://gitlab.example.com:8443/a.png', null],
    'traversal' => ['https://gitlab.example.com/../x', null],
    'script-ish query' => ['https://gitlab.example.com/a.png?x="><script>', 'https://gitlab.example.com/a.png'],
    'null' => [null, null],
]);

test('the private-network override opens RFC 1918 and mesh ranges only', function (): void {
    $resolver = new class implements DnsResolver
    {
        public function resolve(string $hostname): array
        {
            return match ($hostname) {
                'lan.example.com' => ['192.168.1.20'],
                'mesh.example.com' => ['100.64.0.7'],
                'meta.example.com' => ['169.254.169.254'],
                'loop.example.com' => ['127.0.0.1'],
                default => ['140.82.112.6'],
            };
        }
    };
    $guard = new UrlGuard($resolver);
    $private = $guard->withPrivateNetwork();

    expect(fn (): ResolvedTarget => $guard->check('https://lan.example.com/'))->toThrow(UnsafeUrl::class)
        ->and(fn (): ResolvedTarget => $guard->check('http://public.example.com/'))->toThrow(UnsafeUrl::class)
        ->and($private->check('http://lan.example.com:3000/')->ip)->toBe('192.168.1.20')
        ->and($private->check('https://mesh.example.com/')->ip)->toBe('100.64.0.7')
        ->and(fn (): ResolvedTarget => $private->check('https://meta.example.com/'))->toThrow(UnsafeUrl::class)
        ->and(fn (): ResolvedTarget => $private->check('https://loop.example.com/'))->toThrow(UnsafeUrl::class)
        ->and(fn (): ResolvedTarget => $private->check('http://valkey:6379/'))->toThrow(UnsafeUrl::class)
        ->and(fn (): ResolvedTarget => $private->check('ftp://lan.example.com/'))->toThrow(UnsafeUrl::class);
});
