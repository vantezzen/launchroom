<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Data;

/**
 * The SSH host-key fingerprints the user confirmed for a git server.
 */
final class ConfirmHostKeysData extends Data
{
    /**
     * @param  list<string>  $fingerprints
     */
    public function __construct(
        public array $fingerprints,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'fingerprints' => ['required', 'array', 'min:1', 'max:8'],
            'fingerprints.*' => ['string', 'regex:'.Grammar::SSH_FINGERPRINT],
        ];
    }
}
