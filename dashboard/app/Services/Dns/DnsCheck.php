<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Contracts\PublicDnsResolver;
use App\Data\Domains\DnsCheckData;
use App\Support\Hostname;

/**
 * Live DNS checks (docs/05 §5, docs/08 §3.1): does a hostname, or a random
 * name under a wildcard, resolve to the edge servers' addresses?
 */
final readonly class DnsCheck
{
    public function __construct(
        private PublicDnsResolver $resolver,
    ) {}

    /**
     * Checks `*.domain` through a name nobody has a record for
     * (`lr-check-<random>.<domain>`), so only a wildcard can answer.
     *
     * @param  list<string>  $expected
     */
    public function wildcard(string $domain, array $expected): DnsCheckData
    {
        return $this->check('lr-check-'.bin2hex(random_bytes(4)).'.'.$domain, $expected, wildcard: true);
    }

    /**
     * @param  list<string>  $expected
     */
    public function hostname(string $hostname, array $expected): DnsCheckData
    {
        return $this->check($hostname, $expected, wildcard: false);
    }

    /**
     * @param  list<string>  $expected
     */
    private function check(string $hostname, array $expected, bool $wildcard): DnsCheckData
    {
        $hostname = Hostname::normalize($hostname);
        $lookup = $this->resolver->lookup($hostname);
        $subject = $wildcard ? 'The wildcard record' : $hostname;
        $expected = array_values(array_unique(array_map($this->canonical(...), $expected)));
        $addresses = array_values(array_unique(array_map($this->canonical(...), $lookup->addresses)));
        $ours = array_values(array_intersect($addresses, $expected));
        $foreign = array_values(array_diff($addresses, $expected));
        $resolved = implode(', ', $addresses);
        $servers = implode(', ', $expected);
        $matching = implode(', ', $ours);
        $others = implode(', ', $foreign);

        [$status, $message] = match (true) {
            $lookup->status === 'error' => ['error', 'DNS lookup failed: '.($lookup->error ?? 'no resolver answered').' Try again in a moment.'],
            $addresses === [] => ['missing', $wildcard
                ? 'No wildcard record yet. DNS changes can take a few minutes (sometimes hours) to show up.'
                : "No A or AAAA record for {$hostname} yet. DNS changes can take a few minutes (sometimes hours) to show up."],
            $expected === [] => ['unknown', "{$subject} points at {$resolved}, but no edge server has a public address to compare it with."],
            $foreign === [] => ['ok', "{$subject} points at your server ({$matching})."],
            $ours === [] => ['mismatch', "{$subject} points at {$others}, not at your server ({$servers})."],
            default => ['mismatch', "{$subject} also points at {$others}, which is not one of your servers. Remove that record."],
        };

        return new DnsCheckData(
            status: $status,
            hostname: $hostname,
            addresses: $addresses,
            expected: $expected,
            cname: $lookup->cnames[0] ?? null,
            message: $message,
            checkedAt: now()->toIso8601ZuluString(),
        );
    }

    private function canonical(string $ip): string
    {
        $packed = @inet_pton($ip);

        return $packed === false ? strtolower($ip) : (string) inet_ntop($packed);
    }
}
