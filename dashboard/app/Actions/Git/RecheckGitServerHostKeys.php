<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Contracts\Git\SshRemotes;
use App\Enums\Git\GitAuthMethod;
use App\Exceptions\DomainRuleViolation;
use App\Models\Git\GitConnection;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Support\Git\HostKey;
use App\Support\Git\SshUrl;

/**
 * "Check host key again" (docs/19 §4.2): reads the server's keys now. If
 * they differ from the pinned ones, the new keys replace them **unconfirmed**
 * (clones stop until the user confirms); identical keys change nothing.
 */
final readonly class RecheckGitServerHostKeys
{
    public function __construct(
        private SshRemotes $ssh,
    ) {}

    /**
     * @return bool whether the keys changed
     *
     * @throws DomainRuleViolation
     */
    public function handle(GitConnection $connection): bool
    {
        $url = SshUrl::parse($connection->base_url);

        if ($connection->auth_method !== GitAuthMethod::DeployKey || ! $url instanceof SshUrl) {
            throw DomainRuleViolation::on('connection', 'Only SSH git servers have host keys.');
        }

        try {
            $keys = $this->ssh->hostKeys($url, $connection->allow_private_network);
        } catch (GitConnectionUnusable $e) {
            throw DomainRuleViolation::on('connection', $e->getMessage());
        }

        $now = array_map(static fn (HostKey $key): array => $key->toArray(), $keys);
        $before = array_column($connection->host_keys ?? [], 'fingerprint');
        $after = array_column($now, 'fingerprint');
        sort($before);
        sort($after);

        if ($keys === [] || $before === $after) {
            return false;
        }

        $connection->forceFill(['host_keys' => $now, 'host_keys_confirmed_at' => null])->save();

        return true;
    }
}
