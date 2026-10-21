<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\FailureFixAction;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * "Fix and redeploy" on an explained failure (flows §6): the setting the
 * explanation suggests. `add_variable` never comes here: it opens the
 * variable sheet.
 */
final class FailureFixData extends Data
{
    public function __construct(
        public FailureFixAction $action,
        #[Min(1), Max(65535)]
        public ?int $port = null,
        #[Min(1), Max(3600)]
        public ?int $seconds = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'port' => ['nullable', 'required_if:action,set_port', 'integer'],
            'seconds' => ['nullable', 'required_if:action,raise_health_timeout', 'integer'],
        ];
    }
}
