<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Data\Domains\DnsCheckData;
use Spatie\LaravelData\Data;

/**
 * A DNS record to change after a move, with its live check.
 */
final class DnsSwitchRecordData extends Data
{
    /**
     * @param  list<DnsSwitchValueData>  $values  set it to these
     * @param  list<string>  $remove  take these out
     */
    public function __construct(
        /** dashboard | apps | domain */
        public string $kind,
        /** "Dashboard", "Apps (wildcard)", "Custom domain". */
        public string $label,
        /** The record's name: "dash.example.com", "*.apps.example.com". */
        public string $name,
        public array $values,
        public array $remove,
        /** Custom domains: "web (Shop / production)". */
        public ?string $usedBy,
        /** Null until checked. */
        public ?DnsCheckData $check,
    ) {}
}
