<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\Alerts\AlertChannel;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Crypto\SecretKey;
use JsonException;

/**
 * Envelope encryption for notification channels (docs/15 §3.5, docs/09
 * R13), the backup destination scheme: a per-channel data key wrapped by
 * the KEK (context `alert_channel:<public id>`), the secret JSON
 * `{url, signingSecret?}` encrypted with associated data
 * `alert_channel|<team id>|<public id>|secret`, so ciphertexts can't move
 * between channels or teams.
 */
final readonly class AlertChannelSecrets
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
     * Encrypts the secret onto the channel (which must have its public id
     * and team). A new data key each time.
     */
    public function seal(AlertChannel $channel, ChannelSecret $secret): void
    {
        $dek = $this->envelope->generateDataKey();
        $payload = ['url' => $secret->url];

        if ($secret->signingSecret !== null) {
            $payload['signingSecret'] = $secret->signingSecret;
        }

        $channel->wrapped_dek = $this->envelope->wrap($dek, $this->wrapContext($channel));
        $channel->kek_id = $this->envelope->kekId();
        $channel->secret = $dek->encrypt(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $this->associatedData($channel));
    }

    /**
     * @throws DecryptionFailed
     */
    public function reveal(AlertChannel $channel): ChannelSecret
    {
        if ($channel->secret === null || $channel->wrapped_dek === null) {
            throw DecryptionFailed::malformed();
        }

        $json = $this->dataKey($channel)->decrypt($channel->secret, $this->associatedData($channel));

        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DecryptionFailed::malformed();
        }

        if (! is_array($decoded) || ! is_string($decoded['url'] ?? null)) {
            throw DecryptionFailed::malformed();
        }

        $signing = $decoded['signingSecret'] ?? null;

        return new ChannelSecret($decoded['url'], is_string($signing) ? $signing : null);
    }

    /**
     * Re-wraps the stored DEK from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(AlertChannel $channel, Envelope $from, Envelope $to): void
    {
        if ($channel->wrapped_dek === null) {
            return;
        }

        $channel->wrapped_dek = $from->rewrap($channel->wrapped_dek, $this->wrapContext($channel), $to);
        $channel->kek_id = $to->kekId();
    }

    private function dataKey(AlertChannel $channel): SecretKey
    {
        return $this->envelope->unwrap((string) $channel->wrapped_dek, $this->wrapContext($channel));
    }

    private function wrapContext(AlertChannel $channel): string
    {
        return 'alert_channel:'.$channel->public_id;
    }

    private function associatedData(AlertChannel $channel): string
    {
        return "alert_channel|{$channel->team_id}|{$channel->public_id}|secret";
    }
}
