<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use App\Enums\ConfigAsCode\ConfigFileStatus;
use Spatie\LaravelData\Data;

/**
 * launchroom.json on the staged changes review (docs/18 §3.4–3.5): whether
 * its changes are among the staged ones, and which staged items it manages
 * (the file sets them again on the next push).
 */
final class StagedConfigFileData extends Data
{
    /**
     * @param  list<string>  $managedPaths  staged item paths the file manages
     */
    public function __construct(
        public string $path,
        public ?string $commitSha,
        public ConfigFileStatus $status,
        /** The file's changes wait here (another change was staged when it was read). */
        public bool $waiting,
        public array $managedPaths,
    ) {}
}
