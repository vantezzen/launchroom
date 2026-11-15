<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Attributes\PublicIdOf;
use App\Data\Services\DatabaseSource;
use App\Enums\DatabaseEngine;
use App\Enums\ServiceKind;
use App\Support\Git\GitRef;
use App\Support\Protocol\Grammar;
use App\Support\PublicId;
use Closure;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * "Add service" from the canvas: one form for every kind. Which fields are
 * required depends on the kind; fields of other kinds are ignored.
 */
final class AddServiceData extends Data
{
    /**
     * @param  list<string>|null  $startCommand  argv, never a shell string
     * @param  list<string>|null  $connectTo  databases: services to connect to it right away (`svc_…`)
     */
    public function __construct(
        #[Min(1), Max(63)]
        public string $name,
        public ServiceKind $kind,
        /** Picker repository id (`ghr_…` GitHub, `gir_…` other providers) for app services. */
        #[PublicIdOf('ghr', 'gir')]
        public ?string $repository = null,
        #[Max(255)]
        public ?string $branch = null,
        #[Regex('#^(?:'.Grammar::REL_PATH_BODY.')?$#D'), Max(1024)]
        public ?string $rootDirectory = null,
        #[Max(512)]
        public ?string $image = null,
        #[Min(1), Max(65535)]
        public ?int $port = null,
        public ?DatabaseEngine $engine = null,
        #[Regex(DatabaseSource::VERSION)]
        public ?string $version = null,
        #[PublicIdOf('svc')]
        public ?string $parentServiceId = null,
        public ?array $startCommand = null,
        public ?CanvasPositionData $position = null,
        public ?array $connectTo = null,
    ) {}

    /**
     * @return array<string, list<string|Closure>>
     */
    public static function rules(): array
    {
        return [
            'repository' => ['required_if:kind,app'],
            'image' => ['required_if:kind,image'],
            'engine' => ['required_if:kind,database'],
            'version' => ['required_if:kind,database'],
            'parentServiceId' => ['required_if:kind,worker,cron'],
            'startCommand' => ['nullable', 'array', 'min:1', 'max:256', 'required_if:kind,worker,cron'],
            'startCommand.*' => ['string', 'max:4096'],
            'connectTo' => ['nullable', 'array', 'max:20', 'prohibited_unless:kind,database'],
            'connectTo.*' => ['string', 'distinct', 'regex:'.PublicId::regex('svc')],
            'branch' => ['nullable', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! GitRef::isValid('refs/heads/'.$value)) {
                    $fail('Enter a valid branch name.');
                }
            }],
        ];
    }
}
