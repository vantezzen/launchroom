<?php

declare(strict_types=1);

namespace App\Data\Instance;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * First-run "create owner" input (docs/08 §3.1, docs/09 R10).
 */
final class CreateOwnerData extends Data
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        #[SensitiveParameter, Max(128)]
        public string $token,
        public string $name,
        public string $email,
        #[SensitiveParameter]
        public string $password,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [...self::profileRules(), 'password' => self::passwordRules()];
    }
}
