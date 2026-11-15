<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\DnsResolver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * The dev `git-fixtures` remote for plain unconnected git sources, as
 * `UnconnectedGitProvider` reaches it through SafeHttpClient: the host
 * resolves on a private network, and it answers like `git http-backend`
 * (v0 ref advertisement; protocol v2 isn't spoken, so the v2 request falls
 * back).
 */
final class GitFixtures
{
    public const string ADDRESS = '172.30.0.9';

    public static function resolve(): void
    {
        $fallback = resolve(DnsResolver::class);

        app()->instance(DnsResolver::class, new readonly class($fallback) implements DnsResolver
        {
            public function __construct(private DnsResolver $fallback) {}

            public function resolve(string $hostname): array
            {
                return $hostname === 'git-fixtures' ? [GitFixtures::ADDRESS] : $this->fallback->resolve($hostname);
            }
        });
    }

    /**
     * `refs/heads/$branch` (and HEAD) at $sha.
     */
    public static function advertise(string $sha, string $branch = 'main'): PromiseInterface
    {
        $pkt = static fn (string $line): string => sprintf('%04x', strlen($line) + 4).$line;

        return Http::response(
            $pkt("# service=git-upload-pack\n").'0000'.$pkt("{$sha} HEAD\0multi_ack side-band-64k\n").$pkt("{$sha} refs/heads/{$branch}\n").'0000',
            200,
            ['Content-Type' => 'application/x-git-upload-pack-advertisement'],
        );
    }

    public static function fake(string $sha, string $branch = 'main'): void
    {
        self::resolve();
        Http::fake(['http://git-fixtures:8080/*' => static fn (): PromiseInterface => self::advertise($sha, $branch)]);
    }
}
