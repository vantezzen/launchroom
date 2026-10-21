<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\ServerRole;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

final class CreateEnrollmentData extends Data
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public array $roles,
        // Display only; host-side names are generated from ids (docs/09 R3).
        #[Max(64), Regex('/^[\pL\pN ._-]+$/u')]
        public ?string $name = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['string', 'distinct', Rule::enum(ServerRole::class)],
        ];
    }

    /**
     * @return list<ServerRole>
     */
    public function serverRoles(): array
    {
        return array_values(array_unique(array_map(ServerRole::from(...), $this->roles), SORT_REGULAR));
    }
}
