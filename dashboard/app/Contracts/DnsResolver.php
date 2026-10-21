<?php

declare(strict_types=1);

namespace App\Contracts;

interface DnsResolver
{
    /**
     * Resolve a hostname to its A and AAAA records.
     *
     * @return list<string> IP addresses; empty if the name doesn't resolve
     */
    public function resolve(string $hostname): array;
}
