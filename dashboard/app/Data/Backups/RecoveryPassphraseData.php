<?php

declare(strict_types=1);

namespace App\Data\Backups;

use SensitiveParameter;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;

/**
 * The recovery passphrase (docs/14 §4), typed twice.
 */
final class RecoveryPassphraseData extends Data
{
    public function __construct(
        #[Hidden, SensitiveParameter]
        public string $passphrase,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['passphrase' => ['required', 'string', 'min:12', 'max:1024', 'confirmed']];
    }
}
