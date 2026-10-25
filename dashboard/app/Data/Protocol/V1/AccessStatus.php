<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * TaskHeartbeat.access (protocol/schema/v1/task-heartbeat.json): the state
 * of a running temporary access (docs/03 §12.1).
 */
final class AccessStatus extends Data
{
    public const array EVENT_TYPES = ['connected', 'auth_failed', 'locked'];

    /**
     * @param  array{type: string, sha256: string, publicKey: string}|null|Optional  $hostKey
     * @param  array{sha256: string, pem: string}|null|Optional  $certificate
     * @param  list<string>|Optional  $privateAddresses
     * @param  list<array{at: string, type: string, remote?: string, method?: string, channel?: string, count?: int}>|Optional  $events
     * @param  array{uid: int, gid: int}|null|Optional  $owner  who SFTP reads and writes as in a volume access (D67)
     */
    public function __construct(
        #[Min(1024), Max(65535)]
        public int $port,
        #[Min(0)]
        public int $activeConnections,
        public array|null|Optional $hostKey = new Optional,
        public array|null|Optional $certificate = new Optional,
        #[Max(63)]
        public string|Optional $database = new Optional,
        #[Max(4)]
        public array|Optional $privateAddresses = new Optional,
        public bool|Optional $behindNat = new Optional,
        #[Max(64)]
        public string|Optional $lastActivityAt = new Optional,
        #[Max(50)]
        public array|Optional $events = new Optional,
        #[Min(0)]
        public int|Optional $dropped = new Optional,
        public array|null|Optional $owner = new Optional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'hostKey.type' => ['sometimes', 'string', 'in:ssh-ed25519'],
            'hostKey.sha256' => ['sometimes', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/D'],
            'hostKey.publicKey' => ['sometimes', 'string', 'max:256', 'regex:/^ssh-ed25519 [A-Za-z0-9+\/]+={0,2}$/D'],
            'certificate.sha256' => ['sometimes', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'certificate.pem' => ['sometimes', 'string', 'max:4096', 'starts_with:-----BEGIN CERTIFICATE-----'],
            'database' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_]{1,63}$/D'],
            'privateAddresses.*' => ['string', 'ip'],
            'events.*.at' => ['required', 'string', 'max:64'],
            'events.*.type' => ['required', 'string', 'in:'.implode(',', self::EVENT_TYPES)],
            'events.*.remote' => ['sometimes', 'string', 'ip'],
            'events.*.method' => ['sometimes', 'string', 'in:password,publickey'],
            'events.*.channel' => ['sometimes', 'string', 'in:shell,exec,sftp,database'],
            'events.*.count' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'owner' => ['sometimes', 'nullable', 'array:uid,gid'],
            'owner.uid' => ['required_with:owner', 'integer', 'min:0', 'max:2147483647'],
            'owner.gid' => ['required_with:owner', 'integer', 'min:0', 'max:2147483647'],
        ];
    }
}
