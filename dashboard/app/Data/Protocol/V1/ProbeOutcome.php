<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * The outcome of a run_once `probe` (docs/20 §6): did the app accept
 * connections on its port in time.
 */
final class ProbeOutcome extends Data
{
    /**
     * @param  list<int>|Optional  $listeningPorts  ports the process did open when the probed one stayed closed
     */
    public function __construct(
        public bool $listening,
        #[Min(0)]
        public int $afterMs,
        public bool $exited,
        #[Min(100), Max(599)]
        public int|Optional $httpStatus = new Optional,
        #[Max(16)]
        public array|Optional $listeningPorts = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['listeningPorts.*' => ['integer', 'min:1', 'max:65535']];
    }
}
