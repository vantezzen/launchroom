<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Casts\ServiceSourceCast;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Add a service to a project (all its persistent environments, staged).
 * `source` is the ServiceSource union with a `type` discriminator
 * (git | image | database); workers/crons take their parent's source.
 */
final class CreateServiceData extends Data
{
    /**
     * @param  array<string, mixed>|null  $source
     * @param  list<string>|null  $startCommand
     */
    public function __construct(
        #[Min(1), Max(63)]
        public string $name,
        public ServiceKind $kind,
        public ?array $source = null,
        #[Regex('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D')]
        public ?string $slug = null,
        #[Max(64)]
        public ?string $icon = null,
        #[Regex('/^svc_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
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

    public function serviceSource(): ?ServiceSource
    {
        if ($this->source === null) {
            return null;
        }

        try {
            $type = $this->source['type'] ?? null;
            $fields = $this->source;
            unset($fields['type']);

            return match ($type) {
                'git' => GitSource::validateAndCreate($fields),
                'image' => ImageSource::validateAndCreate($fields),
                'database' => DatabaseSource::validateAndCreate($fields),
                default => ServiceSourceCast::fromArray($this->source),
            };
        } catch (InvalidArgumentException) {
            throw DomainRuleViolation::on('source', 'Unknown source type.');
        }
    }
}
