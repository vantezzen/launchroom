<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Data\Attributes\ChangeAffects;
use App\Data\Attributes\Promotion;
use App\Enums\ChangeEffect;
use App\Enums\PromotionClass;
use App\Enums\ServiceKind;
use App\Enums\WorkloadCapability;
use App\Support\Protocol\Grammar;
use App\Support\Services\ConfigFileRules;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\In;
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
     * @param  list<NamedCommand>  $commands  one-off commands named in launchroom.json, listed in the Commands tab (D105)
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
        /** How long the Roll out step may take (D68); null: ROLLOUT_TIMEOUT_DEFAULT. Pulls that make progress extend it. */
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::Structural), Min(60), Max(3600)]
        public ?int $rolloutTimeoutSeconds = null,
        /** How long a new container may take to become healthy (D68); null: derived from the health check. */
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::Structural), Min(30), Max(3600)]
        public ?int $healthTimeoutSeconds = null,
        /** How long the process may take to finish its work after the stop signal before it is killed (docs/04 §8); null: stopTimeout(). */
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural), Min(1), Max(300)]
        public ?int $stopTimeoutSeconds = null,
        /** The signal a stop sends first (SIGTERM or SIGINT); null: SIGTERM. */
        #[ChangeAffects(ChangeEffect::Restart), Promotion(PromotionClass::Structural), In(self::STOP_SIGNALS)]
        public ?string $stopSignal = null,
        /**
         * The app handles two copies on its volume: a deploy starts the new
         * version next to the old one as for services without volumes. Off:
         * the old version stops first, with a short downtime (docs/04 §8).
         */
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::Structural)]
        public bool $overlapOnVolumes = false,
        /** Named one-off commands (D105): nothing runs until someone runs one, so a change needs no deployment. */
        #[ChangeAffects(ChangeEffect::None), Promotion(PromotionClass::Structural), DataCollectionOf(NamedCommand::class)]
        public array $commands = [],
    ) {}

    public const int ROLLOUT_TIMEOUT_DEFAULT = 600;

    /** The signals launchroomd stops a workload with (protocol StopSignal). */
    public const array STOP_SIGNALS = ['SIGTERM', 'SIGINT'];

    /** launchroomd's own grace period: a web service without a setting renders no stop spec. */
    public const int STOP_TIMEOUT_WEB = 10;

    /** Workers and crons finish a job before they exit (POLY12, PHP-13). */
    public const int STOP_TIMEOUT_PROCESS = 30;

    /**
     * How long a stop waits: the explicit setting, else the build's hint
     * for the process ($hint, a Laravel queue worker gets 60 s), else 30 s
     * for workers and crons and 10 s for web services.
     */
    public function stopTimeout(ServiceKind $kind, ?int $hint = null): int
    {
        return $this->stopTimeoutSeconds ?? $hint ?? ($kind->isWeb() ? self::STOP_TIMEOUT_WEB : self::STOP_TIMEOUT_PROCESS);
    }

    public function rolloutTimeout(): int
    {
        return $this->rolloutTimeoutSeconds ?? self::ROLLOUT_TIMEOUT_DEFAULT;
    }

    /**
     * How long a new container may take to become healthy: the explicit
     * setting, else the health check's own bound (start period plus the
     * failures it allows, plus a minute), at least 90 seconds.
     */
    public function healthTimeout(): int
    {
        if ($this->healthTimeoutSeconds !== null) {
            return $this->healthTimeoutSeconds;
        }

        $health = $this->healthcheck;

        return max(90, $health->startPeriodSeconds + ($health->intervalSeconds + $health->timeoutSeconds) * $health->failureThreshold + 60);
    }

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
            'capabilities' => ['sometimes', 'array', 'max:'.count(WorkloadCapability::cases())],
            'capabilities.*' => ['string', 'distinct', 'in:'.implode(',', WorkloadCapability::values())],
            'files' => ['sometimes', 'array', 'max:'.ConfigFileRules::MAX_FILES],
            'files.*.path' => ['required', 'string', 'max:255'],
            'files.*.content' => ['present', 'string', 'max:'.ConfigFileRules::MAX_BYTES],
            'commands' => ['sometimes', 'array', 'max:'.NamedCommand::MAX_PER_SERVICE],
            'commands.*.name' => ['required', 'string', 'regex:'.Grammar::SLUG, 'distinct'],
            'commands.*.command' => ['required', 'array', 'min:1', 'max:256'],
            'commands.*.command.*' => ['string', 'max:4096', 'not_regex:/\x00/'],
            'commands.*.description' => ['nullable', 'string', 'max:'.NamedCommand::MAX_DESCRIPTION],
            'commands.*.danger' => ['sometimes', 'boolean'],
        ];
    }
}
