<?php

declare(strict_types=1);

namespace App\Services\Ingress;

use App\Enums\DnsProvider;
use App\Enums\IngressCredentialPurpose;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use SensitiveParameter;

/**
 * The DNS provider's credentials (docs/05 §5, D47): one envelope-encrypted
 * `ingress_credentials` row (purpose `dns_token`, IngressSecrets) holding a
 * JSON object of the provider's values (DnsProvider::keys), prefixed with a
 * format marker. A row written before D47 holds the bare API token and
 * reads as `{"token": …}`.
 */
final readonly class DnsCredentials
{
    private const string FORMAT = 'lr-dns/1:';

    public function __construct(
        private IngressSecrets $secrets,
    ) {}

    /**
     * @param  array<string, string>  $values
     */
    public function put(#[SensitiveParameter] array $values): void
    {
        ksort($values);
        $this->secrets->put(IngressCredentialPurpose::DnsToken, self::FORMAT.json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, string>|null
     *
     * @throws DecryptionFailed
     */
    public function get(): ?array
    {
        $stored = $this->secrets->get(IngressCredentialPurpose::DnsToken);

        if ($stored === null) {
            return null;
        }

        if (! str_starts_with($stored, self::FORMAT)) {
            return ['token' => $stored];
        }

        $decoded = json_decode(substr($stored, strlen(self::FORMAT)), true);

        if (! is_array($decoded)) {
            throw DecryptionFailed::malformed();
        }

        return array_filter($decoded, static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The stored values when they are complete for $provider, else null.
     *
     * @return array<string, string>|null
     */
    public function for(DnsProvider $provider): ?array
    {
        $values = $this->get();

        if ($values === null) {
            return null;
        }

        $out = [];

        foreach ($provider->keys() as $key) {
            if (($values[$key] ?? '') === '') {
                return null;
            }

            $out[$key] = $values[$key];
        }

        return $out;
    }

    public function forget(): void
    {
        $this->secrets->forget(IngressCredentialPurpose::DnsToken);
    }

    public function fingerprint(): ?string
    {
        return $this->secrets->fingerprint(IngressCredentialPurpose::DnsToken);
    }
}
