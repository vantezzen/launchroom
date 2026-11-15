<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use App\Data\Attributes\PublicIdOf;
use App\Support\PublicId;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * "Stage in target" (docs/06 §6.1): the selected items (conflicts included:
 * selecting one takes the source's side) and the services whose images are
 * promoted too. `deploy` is "Promote and deploy" (stage and deploy the
 * target in one transaction).
 */
final class PromoteChangesData extends Data
{
    /**
     * @param  list<PromoteItemInput>  $items
     * @param  list<string>  $images  service public ids
     */
    public function __construct(
        #[PublicIdOf('env')]
        public string $from,
        #[PublicIdOf('env')]
        public string $to,
        #[Max(1000)]
        public array $items = [],
        #[Max(200)]
        public array $images = [],
        public bool $deploy = false,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'images.*' => ['string', 'regex:'.PublicId::regex('svc')],
        ];
    }
}
