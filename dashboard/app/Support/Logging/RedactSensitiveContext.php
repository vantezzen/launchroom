<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\SensitiveInput;
use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;

/**
 * Log channel tap (config/logging.php): a Monolog processor that replaces
 * the values of sensitive context keys (password, token, secret,
 * authorization, …; see SensitiveInput) with "[redacted]", at any depth, in
 * both `context` and `extra` (docs/09 R16: "log processors redact known
 * keys"). A safety net: log calls still must not pass secrets.
 */
final class RedactSensitiveContext
{
    public const string REDACTED = '[redacted]';

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
                context: self::redact($record->context),
                extra: self::redact($record->extra),
            ));
        }
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && SensitiveInput::isSensitive($key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value) && $depth < 8) {
                $data[$key] = self::redact($value, $depth + 1);
            }
        }

        return $data;
    }
}
