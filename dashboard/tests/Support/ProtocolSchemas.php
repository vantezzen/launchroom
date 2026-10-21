<?php

declare(strict_types=1);

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Validates documents against protocol/schema/v1 (the contract with the daemon).
 */
final class ProtocolSchemas
{
    private static ?Validator $validator = null;

    public static function root(): string
    {
        $root = dirname(__DIR__, 3).'/protocol';

        if (! is_dir($root.'/schema/v1')) {
            throw new RuntimeException("protocol/ not found at {$root}; the test container must mount it (dev/compose.yml).");
        }

        return $root;
    }

    /**
     * Errors for $json against schema $name (e.g. "node-state"), [] if valid.
     *
     * @return array<string, mixed>
     */
    public static function errors(string $name, string $json): array
    {
        $result = self::validator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), "https://launchroom.vantezzen.io/schema/v1/{$name}.json");

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();

        return $error instanceof ValidationError ? (new ErrorFormatter)->format($error) : ['unknown'];
    }

    private static function validator(): Validator
    {
        if (! self::$validator instanceof Validator) {
            $validator = new Validator;
            $validator->resolver()?->registerPrefix('https://launchroom.vantezzen.io/schema/v1/', self::root().'/schema/v1/');
            $validator->setMaxErrors(10);
            self::$validator = $validator;
        }

        return self::$validator;
    }
}
