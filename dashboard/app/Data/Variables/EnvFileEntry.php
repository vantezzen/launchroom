<?php

declare(strict_types=1);

namespace App\Data\Variables;

use App\Enums\VariableType;
use SensitiveParameter;

/**
 * One parsed `.env` line (docs/06 §3.4 paste-import). Not a Data object: it
 * holds a plaintext value and must never be serialized into props or logs.
 */
final readonly class EnvFileEntry
{
    public function __construct(
        public string $key,
        #[SensitiveParameter]
        public string $value,
        public int $line,
        public VariableType $suggestedType,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => $this->key, 'value' => '[redacted]'];
    }
}
