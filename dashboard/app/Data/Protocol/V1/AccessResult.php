<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * TaskCompletion.access (protocol/schema/v1/task-completion.json): how a
 * temporary access ended (docs/03 §12.1).
 */
final class AccessResult extends Data
{
    public const array END_REASONS = ['ended', 'idle', 'deadline', 'locked', 'refused', 'error'];

    /**
     * @param  list<array{at: string, type: string, remote?: string, method?: string, channel?: string, count?: int}>|Optional  $events
     */
    public function __construct(
        #[In(self::END_REASONS)]
        public string $endReason,
        #[Min(0)]
        public int $connections,
        #[Min(0)]
        public int $authFailures,
        #[Min(0)]
        public int $bytesIn,
        #[Min(0)]
        public int $bytesOut,
        #[Max(64)]
        public string|Optional $startedAt = new Optional,
        #[Max(50)]
        public array|Optional $events = new Optional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'events.*.at' => ['required', 'string', 'max:64'],
            'events.*.type' => ['required', 'string', 'in:'.implode(',', AccessStatus::EVENT_TYPES)],
            'events.*.remote' => ['sometimes', 'string', 'ip'],
            'events.*.method' => ['sometimes', 'string', 'in:password,publickey'],
            'events.*.channel' => ['sometimes', 'string', 'in:shell,exec,sftp,database'],
            'events.*.count' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
