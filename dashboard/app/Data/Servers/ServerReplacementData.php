<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\ServerReplacementStatus;
use App\Models\ServerReplacement;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Progress of a "Replace server" run (docs/14 §5.3).
 */
final class ServerReplacementData extends Data
{
    /**
     * @param  list<ReplacedServiceProgressData>  $services
     */
    public function __construct(
        public string $id,
        public ServerReplacementStatus $status,
        public string $oldServer,
        public string $newServer,
        public string $newServerId,
        public array $services,
        public bool $recovery,
        public ?string $error,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $finishedAt,
    ) {}

    public static function fromReplacement(ServerReplacement $replacement): self
    {
        return new self(
            $replacement->public_id,
            $replacement->status,
            $replacement->oldServer->name,
            $replacement->newServer->name,
            $replacement->newServer->public_id,
            array_map(ReplacedServiceProgressData::fromService(...), array_values($replacement->services->items())),
            $replacement->recovery,
            $replacement->error,
            $replacement->created_at,
            $replacement->finished_at,
        );
    }
}
