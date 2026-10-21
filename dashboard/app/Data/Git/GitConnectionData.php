<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Models\Git\GitConnection;
use App\Support\Git\HostKey;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A GitLab, Gitea/Forgejo or plain git connection on Settings → Git
 * (docs/19 §4). Never carries a secret: the deploy key is the public half.
 */
final class GitConnectionData extends Data
{
    /**
     * @param  list<HostKeyData>  $hostKeys
     */
    public function __construct(
        public string $id,
        public GitProviderKind $provider,
        /** Logo: gitlab, gitea, forgejo, git. */
        public string $serverKind,
        public string $serverLabel,
        public string $name,
        public string $host,
        public string $url,
        public ?string $accountLogin,
        public GitAuthMethod $authMethod,
        public GitConnectionStatus $status,
        public string $statusLabel,
        public ?string $statusReason,
        public bool $webhooksHealthy,
        public ?CarbonInterface $lastWebhookAt,
        public int $repositoriesCount,
        public array $hostKeys,
        public bool $hostKeysConfirmed,
        public ?string $deployKey,
        public ?string $webhookUrl,
        public bool $allowPrivateNetwork,
        public bool $canManage,
    ) {}

    public static function fromConnection(GitConnection $connection, bool $canManage, int $repositories, ?string $webhookUrl): self
    {
        return new self(
            id: $connection->public_id,
            provider: $connection->provider,
            serverKind: $connection->server_kind->value,
            serverLabel: $connection->server_kind->label(),
            name: $connection->name,
            host: $connection->host(),
            url: $connection->base_url,
            accountLogin: $connection->account_login,
            authMethod: $connection->auth_method,
            status: $connection->status,
            statusLabel: $connection->status->label(),
            statusReason: $connection->status_reason,
            webhooksHealthy: $connection->hasHealthyWebhooks(),
            lastWebhookAt: $connection->webhook_last_received_at,
            repositoriesCount: $repositories,
            hostKeys: array_values(array_map(
                static fn (HostKey $key): HostKeyData => new HostKeyData($key->type, $key->fingerprint()),
                array_filter(array_map(HostKey::fromArray(...), $connection->host_keys ?? [])),
            )),
            hostKeysConfirmed: $connection->host_keys_confirmed_at !== null,
            deployKey: $connection->ssh_public_key,
            webhookUrl: $webhookUrl,
            allowPrivateNetwork: $connection->allow_private_network,
            canManage: $canManage,
        );
    }
}
