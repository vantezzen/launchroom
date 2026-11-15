<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use SensitiveParameter;
use Spatie\LaravelData\Data;

/**
 * Account details for an invitee who doesn't have an account yet.
 */
final class AcceptInvitationData extends Data
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        public string $name,
        #[SensitiveParameter]
        public string $password,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return ['name' => self::nameRules(), 'password' => self::passwordRules()];
    }
}
