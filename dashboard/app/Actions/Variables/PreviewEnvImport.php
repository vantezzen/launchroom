<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Data\Variables\EnvFileEntry;
use App\Data\Variables\ImportPreviewData;
use App\Data\Variables\ImportPreviewEntryData;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Models\Environment;
use App\Models\Service;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Queries\EnvironmentVariableRows;
use App\Support\Variables\EnvFileParser;
use SensitiveParameter;

/**
 * Parses a pasted `.env` for the import dialog (docs/06 §3.4) without
 * storing anything: every key with its line, suggested type and what
 * importing it into this environment would do. Values are never returned.
 */
final readonly class PreviewEnvImport
{
    public function __construct(
        private EnvironmentVariableRows $rows,
    ) {}

    public function handle(Environment $environment, Service $service, #[SensitiveParameter] string $content): ImportPreviewData
    {
        $parsed = EnvFileParser::parse($content);

        /** @var array<string, Variable> $existing */
        $existing = $this->rows->get($environment, $service)->keyBy('key')->all();

        $entries = array_map(static function (EnvFileEntry $entry) use ($existing, $environment): ImportPreviewEntryData {
            $variable = $existing[$entry->key] ?? null;
            $status = match (true) {
                $variable === null => 'new',
                $variable->targets->count() === 1 && $variable->targets->every(static fn (VariableTarget $target): bool => $target->environment_id === $environment->id) => 'update',
                default => 'conflict',
            };

            return new ImportPreviewEntryData(
                key: $entry->key,
                line: $entry->line,
                suggestedType: $variable instanceof Variable ? $variable->type : $entry->suggestedType,
                browserInlined: VariableExposure::isBrowserInlined($entry->key),
                status: $status,
                existingSecret: $variable instanceof Variable && $variable->type === VariableType::Secret,
            );
        }, $parsed->entries);

        return new ImportPreviewData($entries, $parsed->errors, $parsed->warnings);
    }
}
