<?php

declare(strict_types=1);

namespace App\Data\Projects;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * A suggested process (SuggestedProcessData) the user kept when importing:
 * created as a worker service of its app with this start command (argv,
 * never a shell string).
 */
final class ImportProcessData extends Data
{
    /**
     * @param  list<string>  $command
     */
    public function __construct(
        #[Min(1), Max(63)]
        public string $name,
        public array $command,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['command' => ['required', 'array', 'list', 'min:1', 'max:64'], 'command.*' => ['string', 'max:4096']];
    }
}
