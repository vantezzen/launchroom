<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * POST /daemon/v1/logs (protocol/schema/v1/log-batch.json).
 */
final class LogBatch extends Data
{
    /**
     * @param  list<LogChunk>  $chunks
     */
    public function __construct(
        #[In([ProtocolDocument::API_VERSION])]
        public string $apiVersion,
        #[In(['LogBatch'])]
        public string $kind,
        #[Min(1), Max(256)]
        public array $chunks,
    ) {}
}
