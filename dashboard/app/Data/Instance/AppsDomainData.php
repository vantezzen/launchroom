<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * The platform domain (`apps_domain`), or null to clear it.
 */
final class AppsDomainData extends Data
{
    public function __construct(
        public ?string $appsDomain,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['appsDomain' => ['present', 'nullable', 'string', 'max:253']];
    }
}
