<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Data\Attributes\ChangeAffects;
use App\Data\Attributes\Promotion;
use App\Enums\ChangeEffect;
use App\Enums\PromotionClass;
use App\Support\Blueprints\BlueprintValidator;
use App\Support\Services\ConfigFileRules;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * `service_instances.deploy` (docs/07). Commands are argv arrays that run
 * inside the user's own container, never shell strings (docs/09 §1).
 * All runtime-only: changing them restarts without a rebuild (docs/04 §7).
 */
final class DeploySettings extends Data
{
    /**
     * @param  list<string>|null  $startCommand
     * @param  list<string>|null  $releaseCommand
     * @param  list<string>|null  $seedCommand
     * @param  list<string>|null  $entrypoint  overrides the image's ENTRYPOINT (argv; Compose imports, docs/17)
     * @param  list<string>  $capabilities  added to cap-drop ALL, within launchroomd's allow-list (docs/03 §8)
     * @param  ScheduleSettings|null  $schedule  cron services only: when the job runs; null runs the command continuously
     * @param  list<ConfigFile>  $files  written read-only into the container before it starts (D65, App\Support\Services\ConfigFileRules)
     */
    public function __construct(
        /** Port the process listens on; null for workers or to use the engine default. */
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural), Min(1), Max(65535)]
        public ?int $port = null,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural)]
        public ?array $startCommand = null,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural)]
        public ?array $releaseCommand = null,
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::Structural)]
        public ?array $seedCommand = null,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural)]
        public HealthcheckSettings $healthcheck = new HealthcheckSettings,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural), Min(0), Max(3600)]
        public int $drainSeconds = 10,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural)]
        public ?array $entrypoint = null,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural)]
        public array $capabilities = [],
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural)]
        public ?ScheduleSettings $schedule = null,
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural), DataCollectionOf(ConfigFile::class)]
        public array $files = [],
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'startCommand' => ['nullable', 'array', 'min:1', 'max:256'],
            'startCommand.*' => ['string', 'max:4096'],
            'releaseCommand' => ['nullable', 'array', 'min:1', 'max:256'],
            'releaseCommand.*' => ['string', 'max:4096'],
            'seedCommand' => ['nullable', 'array', 'min:1', 'max:256'],
            'seedCommand.*' => ['string', 'max:4096'],
            'entrypoint' => ['nullable', 'array', 'min:1', 'max:256'],
            'entrypoint.*' => ['string', 'max:4096'],
            'capabilities' => ['sometimes', 'array', 'max:6'],
            'capabilities.*' => ['string', 'distinct', 'in:'.implode(',', BlueprintValidator::CAPABILITIES)],
            'files' => ['sometimes', 'array', 'max:'.ConfigFileRules::MAX_FILES],
            'files.*.path' => ['required', 'string', 'max:255'],
            'files.*.content' => ['present', 'string', 'max:'.ConfigFileRules::MAX_BYTES],
        ];
    }
}
