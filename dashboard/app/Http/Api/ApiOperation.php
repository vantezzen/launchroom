<?php

declare(strict_types=1);

namespace App\Http\Api;

use Attribute;
use Spatie\LaravelData\Data;

/**
 * Documents one /api/v1 operation for the OpenAPI generator (docs/16 §2.4).
 * The ability comes from the route's RequireTokenAbility middleware, the
 * path parameters from the route; this adds what reflection can't see.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ApiOperation
{
    /**
     * @param  class-string<Data>|null  $response  the `data` of the response
     * @param  class-string<Data>|null  $request  JSON body
     * @param  class-string<Data>|null  $query  query parameters
     */
    public function __construct(
        public string $id,
        public string $summary,
        public string $tag,
        public ?string $response = null,
        public bool $list = false,
        public ?string $request = null,
        public ?string $query = null,
        public int $status = 200,
        public ?string $description = null,
        public bool $stream = false,
        public bool $paginated = false,
    ) {}
}
