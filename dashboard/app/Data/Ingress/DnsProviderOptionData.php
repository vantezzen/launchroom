<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use App\Enums\DnsProvider;
use Spatie\LaravelData\Data;

/**
 * A DNS provider the edge Caddy image supports, with its credential form.
 */
final class DnsProviderOptionData extends Data
{
    /**
     * @param  list<DnsCredentialFieldData>  $fields
     */
    public function __construct(
        public DnsProvider $value,
        public string $label,
        public string $help,
        public array $fields,
    ) {}
}
