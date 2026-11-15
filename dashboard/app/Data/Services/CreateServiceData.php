<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Data\Attributes\PublicIdOf;
use App\Enums\ServiceKind;
use App\Support\Slug;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Add a service to a project (all its persistent environments, staged).
 * Workers/crons take their parent's source.
 */
final class CreateServiceData extends Data
{
    /**
     * @param  list<string>|null  $startCommand
     */
    public function __construct(
        #[Min(1), Max(63)]
        public string $name,
        public ServiceKind $kind,
        #[WithCast(ServiceSourceInput::class), LiteralTypeScriptType('App.Data.Services.GitSource | App.Data.Services.ImageSource | App.Data.Services.DatabaseSource | null')]
        public ?ServiceSource $source = null,
        #[Regex(Slug::PATTERN)]
        public ?string $slug = null,
        #[Max(64)]
        public ?string $icon = null,
        #[PublicIdOf('svc')]
        public ?string $parentServiceId = null,
        #[Min(1), Max(65535)]
        public ?int $port = null,
        public ?array $startCommand = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'startCommand' => ['nullable', 'array', 'min:1', 'max:256'],
            'startCommand.*' => ['string', 'max:4096'],
        ];
    }
}
