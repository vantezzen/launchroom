<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use App\Enums\DnsProvider;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * The DNS provider for DNS-01 certificates and its credentials (docs/05 §5).
 * `token` is the single-token providers' field (kept for API callers).
 */
final class DnsProviderInput extends Data
{
    /**
     * @param  array<string, string|null>  $credentials
     */
    public function __construct(
        public DnsProvider $provider,
        #[Hidden, SensitiveParameter, Max(512)]
        public ?string $token = null,
        #[Hidden, SensitiveParameter]
        public array $credentials = [],
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $keys = array_values(array_unique(array_merge(...array_map(static fn (DnsProvider $provider): array => $provider->keys(), DnsProvider::cases()))));

        return [
            'credentials' => ['nullable', 'array:'.implode(',', $keys)],
            'credentials.*' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function secrets(): array
    {
        $credentials = array_map(static fn (mixed $value): ?string => is_string($value) ? $value : null, $this->credentials);

        if ($this->token !== null) {
            $credentials['token'] = $this->token;
        }

        return $credentials;
    }
}
