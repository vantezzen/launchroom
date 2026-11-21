<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Enums\Security\DismissReason;
use App\Support\PublicId;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * "Not affected…" for a whole fix group (docs/22 §5.5): the findings of the
 * services it applies to, the services left out stay open.
 */
final class DismissFindingsData extends Data
{
    /**
     * @param  list<string>  $findings  public ids (`fnd_…`)
     */
    public function __construct(
        public array $findings,
        public DismissReason $reason,
        #[Max(500)]
        public ?string $note = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'findings' => ['required', 'array', 'min:1', 'max:1000'],
            'findings.*' => ['string', 'distinct', 'regex:'.PublicId::regex('fnd')],
        ];
    }
}
