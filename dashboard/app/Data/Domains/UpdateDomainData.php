<?php

declare(strict_types=1);

namespace App\Data\Domains;

use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Data;

/**
 * One domain toggle: HSTS on/off, or else the www redirect on/off.
 */
#[MergeValidationRules]
final class UpdateDomainData extends Data
{
    public function __construct(
        public ?bool $hsts = null,
        public ?bool $wwwRedirect = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['wwwRedirect' => ['required_without:hsts']];
    }
}
