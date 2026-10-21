<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditSink;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Crypto\SecretKey;
use JsonException;
use SensitiveParameter;

/**
 * Envelope encryption for audit sink credentials (docs/09 R13, R34): a
 * per-sink data key wrapped by the KEK (context `audit_sink:<public id>`),
 * the credential JSON encrypted with associated data
 * `audit_sink|instance|<public id>|secret`. Webhooks keep `{url,
 * signingSecret}`, S3 sinks `{accessKeyId, secretAccessKey}`; syslog sinks
 * have none.
 */
final readonly class AuditSinkSecrets
{
    public function __construct(
        private Envelope $envelope,
    ) {}

    /**
     * A fresh webhook signing secret: `lrwh_` + 256 bits, URL-safe.
     */
    public static function generateSigningSecret(): string
    {
        return 'lrwh_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Encrypts the values onto the sink (which must have its public id). A
     * new data key each time.
     *
     * @param  array<string, string>  $values
     */
    public function seal(AuditSink $sink, #[SensitiveParameter] array $values): void
    {
        $dek = $this->envelope->generateDataKey();
        $sink->wrapped_dek = $this->envelope->wrap($dek, $this->wrapContext($sink));
        $sink->kek_id = $this->envelope->kekId();
        $sink->secret = $dek->encrypt(json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $this->associatedData($sink));
    }

    /**
     * @return array<string, string>
     *
     * @throws DecryptionFailed
     */
    public function reveal(AuditSink $sink): array
    {
        if ($sink->secret === null || $sink->wrapped_dek === null) {
            throw DecryptionFailed::malformed();
        }

        $json = $this->dataKey($sink)->decrypt($sink->secret, $this->associatedData($sink));

        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DecryptionFailed::malformed();
        }

        if (! is_array($decoded)) {
            throw DecryptionFailed::malformed();
        }

        $values = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * Re-wraps the stored DEK from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(AuditSink $sink, Envelope $from, Envelope $to): void
    {
        if ($sink->wrapped_dek === null) {
            return;
        }

        $sink->wrapped_dek = $from->rewrap($sink->wrapped_dek, $this->wrapContext($sink), $to);
        $sink->kek_id = $to->kekId();
    }

    private function dataKey(AuditSink $sink): SecretKey
    {
        return $this->envelope->unwrap((string) $sink->wrapped_dek, $this->wrapContext($sink));
    }

    private function wrapContext(AuditSink $sink): string
    {
        return 'audit_sink:'.$sink->public_id;
    }

    private function associatedData(AuditSink $sink): string
    {
        return "audit_sink|instance|{$sink->public_id}|secret";
    }
}
