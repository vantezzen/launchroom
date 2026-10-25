<?php

declare(strict_types=1);

namespace App\Data\RemoteAccess;

use App\Support\RemoteAccess\AccessPrefix;
use App\Support\RemoteAccess\SshPublicKey;
use Closure;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Starting a temporary access (docs/09 R39): who may connect, how long,
 * keys (SSH and volumes), "allow writes" (databases and volumes), and the
 * volume of a volume access (D67).
 */
final class OpenRemoteAccessData extends Data
{
    public const array DURATIONS = [30, 60, 120, 240];

    /**
     * @param  list<string>  $allowFrom  addresses or prefixes ("203.0.113.7", "2001:db8::/48")
     * @param  list<string>  $publicKeys  OpenSSH public keys (SSH and volumes); none: a password
     */
    public function __construct(
        public array $allowFrom,
        public array $publicKeys = [],
        #[In(self::DURATIONS)]
        public int $durationMinutes = 120,
        public bool $allowWrites = false,
        #[Regex('/^wl_[0-9A-HJKMNP-TV-Z]{26}$/')]
        public ?string $workload = null,
        #[Regex('/^vol_[0-9A-HJKMNP-TV-Z]{26}$/')]
        public ?string $volume = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'allowFrom' => ['required', 'array', 'min:1', 'max:16'],
            'allowFrom.*' => ['required', 'string', 'max:50', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || AccessPrefix::normalize($value) === null) {
                    $fail('Enter an IP address or a range like 203.0.113.0/24 (IPv4 no wider than /8, IPv6 no wider than /32).');
                }
            }],
            'publicKeys' => ['array', 'max:4'],
            'publicKeys.*' => ['required', 'string', 'max:4096', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! SshPublicKey::parse($value) instanceof SshPublicKey) {
                    $fail('Paste an OpenSSH public key (ssh-ed25519, ecdsa-sha2-… or ssh-rsa with at least 3072 bits).');
                }
            }],
        ];
    }

    /**
     * The canonical, unique allow-list.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return array_values(array_unique(array_filter(array_map(AccessPrefix::normalize(...), $this->allowFrom))));
    }

    /**
     * @return list<SshPublicKey>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->publicKeys as $line) {
            $key = SshPublicKey::parse($line);

            if ($key instanceof SshPublicKey) {
                $keys[$key->line()] = $key;
            }
        }

        return array_values($keys);
    }
}
