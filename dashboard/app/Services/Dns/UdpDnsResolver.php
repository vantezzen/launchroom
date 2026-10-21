<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Contracts\PublicDnsResolver;
use App\Support\Hostname;

/**
 * A minimal stub resolver: one UDP query per record type (A, AAAA) to the
 * configured public recursive resolvers (`launchroom.dns.resolvers`, tried
 * in order), each with `launchroom.dns.timeout_seconds`. Recursion is the
 * resolver's job; answers are parsed for A, AAAA and CNAME records only.
 *
 * Why not dns_get_record(): it uses the container's resolver (which may know
 * private names) and its timeout can't be bounded per request.
 */
final class UdpDnsResolver implements PublicDnsResolver
{
    private const int TYPE_A = 1;

    private const int TYPE_CNAME = 5;

    private const int TYPE_AAAA = 28;

    public function lookup(string $hostname): DnsLookup
    {
        $hostname = Hostname::normalize($hostname);

        if (! Hostname::isValid($hostname)) {
            return DnsLookup::failed('Not a valid hostname.');
        }

        /** @var list<string> $resolvers */
        $resolvers = array_values(array_filter(config()->array('launchroom.dns.resolvers'), static fn (mixed $ip): bool => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false));
        $timeout = max(1, config()->integer('launchroom.dns.timeout_seconds'));
        $lastError = 'No DNS resolver is configured.';

        foreach ($resolvers as $resolver) {
            $a = $this->query($resolver, $hostname, self::TYPE_A, $timeout);

            if (is_string($a)) {
                $lastError = $a;

                continue;
            }

            if ($a['nxdomain']) {
                return new DnsLookup('nxdomain');
            }

            $aaaa = $this->query($resolver, $hostname, self::TYPE_AAAA, $timeout);
            $addresses = [...$a['addresses'], ...(is_array($aaaa) ? $aaaa['addresses'] : [])];
            $cnames = array_values(array_unique([...$a['cnames'], ...(is_array($aaaa) ? $aaaa['cnames'] : [])]));

            return DnsLookup::found(array_values(array_unique($addresses)), $cnames);
        }

        return DnsLookup::failed($lastError);
    }

    /**
     * @return array{nxdomain: bool, addresses: list<string>, cnames: list<string>}|string the answer, or an error
     */
    private function query(string $resolver, string $hostname, int $type, int $timeout): array|string
    {
        $id = random_int(0, 0xFFFF);
        $packet = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0).$this->encodeName($hostname).pack('nn', $type, 1);
        $address = str_contains($resolver, ':') ? "udp://[{$resolver}]:53" : "udp://{$resolver}:53";

        $socket = @stream_socket_client($address, $errno, $errstr, $timeout);

        if ($socket === false) {
            return "Could not reach the DNS resolver {$resolver}.";
        }

        try {
            stream_set_timeout($socket, $timeout);

            if (@fwrite($socket, $packet) !== strlen($packet)) {
                return "Could not query the DNS resolver {$resolver}.";
            }

            $response = @fread($socket, 4096);
            $meta = stream_get_meta_data($socket);

            if ($response === false || $response === '' || $meta['timed_out']) {
                return "The DNS resolver {$resolver} did not answer in time.";
            }
        } finally {
            fclose($socket);
        }

        return self::parse($response, $id) ?? "The DNS resolver {$resolver} sent an unreadable answer.";
    }

    private function encodeName(string $hostname): string
    {
        $encoded = '';

        foreach (explode('.', $hostname) as $label) {
            $encoded .= chr(strlen($label)).$label;
        }

        return $encoded."\0";
    }

    /**
     * @return array{nxdomain: bool, addresses: list<string>, cnames: list<string>}|null
     */
    public static function parse(string $response, int $id): ?array
    {
        if (strlen($response) < 12) {
            return null;
        }

        /** @var array{id: int, flags: int, qd: int, an: int} $header */
        $header = unpack('nid/nflags/nqd/nan', $response);

        if ($header['id'] !== $id || ($header['flags'] & 0x8000) === 0) {
            return null;
        }

        $rcode = $header['flags'] & 0x000F;

        if ($rcode === 3) {
            return ['nxdomain' => true, 'addresses' => [], 'cnames' => []];
        }

        if ($rcode !== 0) {
            return null;
        }

        $offset = 12;

        for ($i = 0; $i < $header['qd']; $i++) {
            if (self::readName($response, $offset) === null) {
                return null;
            }

            $offset += 4;
        }

        $addresses = [];
        $cnames = [];

        for ($i = 0; $i < min($header['an'], 64); $i++) {
            if (self::readName($response, $offset) === null || $offset + 10 > strlen($response)) {
                return null;
            }

            /** @var array{type: int, length: int} $record */
            $record = unpack('ntype/nclass/Nttl/nlength', $response, $offset);
            $offset += 10;
            $data = substr($response, $offset, $record['length']);

            if (strlen($data) !== $record['length']) {
                return null;
            }

            if (($record['type'] === self::TYPE_A && $record['length'] === 4) || ($record['type'] === self::TYPE_AAAA && $record['length'] === 16)) {
                $ip = inet_ntop($data);

                if (is_string($ip)) {
                    $addresses[] = $ip;
                }
            } elseif ($record['type'] === self::TYPE_CNAME) {
                $nameOffset = $offset;
                $name = self::readName($response, $nameOffset);

                if ($name !== null) {
                    $cnames[] = $name;
                }
            }

            $offset += $record['length'];
        }

        return ['nxdomain' => false, 'addresses' => $addresses, 'cnames' => $cnames];
    }

    /**
     * Reads a (possibly compressed) name at $offset and advances $offset past it.
     */
    private static function readName(string $packet, int &$offset): ?string
    {
        $labels = [];
        $position = $offset;
        $jumped = false;
        $length = strlen($packet);

        for ($guard = 0; $guard < 128; $guard++) {
            if ($position >= $length) {
                return null;
            }

            $size = ord($packet[$position]);

            if ($size === 0) {
                if (! $jumped) {
                    $offset = $position + 1;
                }

                return strtolower(implode('.', $labels));
            }

            if (($size & 0xC0) === 0xC0) {
                if ($position + 1 >= $length) {
                    return null;
                }

                if (! $jumped) {
                    $offset = $position + 2;
                }

                $position = (($size & 0x3F) << 8) | ord($packet[$position + 1]);
                $jumped = true;

                continue;
            }

            if ($size > 63 || $position + 1 + $size > $length) {
                return null;
            }

            $labels[] = substr($packet, $position + 1, $size);
            $position += 1 + $size;
        }

        return null;
    }
}
