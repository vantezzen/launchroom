<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Actions\Audit\RecordAuditEvent;
use App\Contracts\Git\SshRemotes;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Git\AddGitServerData;
use App\Enums\AuditAction;
use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Enums\Git\GitServerKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\GitConnectionSecrets;
use App\Services\Git\Providers\PlainGitProvider;
use App\Services\Git\Ssh\DeployKey;
use App\Support\Git\GitServerBranches;
use App\Support\Git\HostKey;
use App\Support\Git\SshUrl;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * "Add a repository from any git server" (docs/19 §4.2):
 *
 * - SSH: reads the server's host keys (not trusted yet), generates the
 *   repository's ed25519 deploy key and stores a pending connection; the
 *   user checks the fingerprints and adds the key ({@see ConfirmGitServer});
 * - HTTPS: a public repository, checked with `ls-remote`, active right away.
 */
final readonly class AddGitServer
{
    public function __construct(
        private SshRemotes $ssh,
        private PlainGitProvider $plain,
        private GitConnectionSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(Team $team, User $user, AddGitServerData $data, AuditActor $actor): GitConnection
    {
        if ($data->allowPrivateNetwork && ! $user->is_instance_owner) {
            throw DomainRuleViolation::on('allowPrivateNetwork', 'Only an instance owner can allow a server on a private network (docs/09 R5).');
        }

        $input = trim($data->url);
        $ssh = SshUrl::parse($input);

        $connection = new GitConnection([
            'team_id' => $team->id,
            'provider' => GitProviderKind::Git,
            'server_kind' => GitServerKind::Git,
            'allow_private_network' => $data->allowPrivateNetwork,
            'created_by' => $user->id,
        ]);
        $connection->public_id = PublicId::generate(GitConnection::publicIdPrefix());
        $this->secrets->initialize($connection);

        if ($ssh instanceof SshUrl) {
            $connection->base_url = $ssh->toUrl();
            $connection->name = mb_substr($ssh->displayName(), 0, 100);
            $connection->auth_method = GitAuthMethod::DeployKey;

            try {
                $keys = $this->ssh->hostKeys($ssh, $data->allowPrivateNetwork);
            } catch (GitConnectionUnusable $e) {
                throw DomainRuleViolation::on('url', $e->getMessage());
            }

            if ($keys === []) {
                throw DomainRuleViolation::on('url', "{$ssh->host} didn’t offer a host key launchroom can pin (ed25519, ECDSA or RSA).");
            }

            $deployKey = DeployKey::generate('launchroom-'.strtolower(substr($connection->public_id, 4, 10)));
            $connection->ssh_public_key = $deployKey['publicKey'];
            $connection->host_keys = array_map(static fn (HostKey $key): array => $key->toArray(), $keys);
            $this->secrets->put($connection, 'ssh_private_key', $deployKey['secretKey']);
            sodium_memzero($deployKey['secretKey']);

            return $this->store($connection, $ssh->displayName(), null, $actor);
        }

        $https = $this->httpsUrl($input, $data->allowPrivateNetwork);
        $connection->base_url = $https;
        $connection->name = mb_substr($this->httpsName($https), 0, 100);
        $connection->auth_method = GitAuthMethod::None;

        try {
            $refs = $this->plain->refs($connection);
        } catch (GitConnectionUnusable $e) {
            throw DomainRuleViolation::on('url', $e->getMessage());
        }

        if ($refs === []) {
            throw DomainRuleViolation::on('url', 'That repository has no branches launchroom can see. Plain HTTPS works for public repositories; use SSH with a deploy key for private ones.');
        }

        return $this->store($connection, $this->httpsName($https), GitServerBranches::defaultBranch($refs), $actor, active: true);
    }

    private function store(GitConnection $connection, string $fullName, ?string $defaultBranch, AuditActor $actor, bool $active = false): GitConnection
    {
        return DB::transaction(function () use ($connection, $fullName, $defaultBranch, $actor, $active): GitConnection {
            $connection->save();

            if ($active) {
                $connection->transitionTo(GitConnectionStatus::Active);
            }

            GitRepository::query()->create([
                'team_id' => $connection->team_id,
                'git_connection_id' => $connection->id,
                'external_id' => 0,
                'full_name' => mb_substr($fullName, 0, 255),
                'default_branch' => $defaultBranch,
                'private' => $connection->auth_method === GitAuthMethod::DeployKey,
                'archived' => false,
            ]);

            $this->audit->handle(new AuditEntryData(AuditAction::GitConnectionCreated, $actor, $connection->team_id, 'git_connection', $connection->public_id, [
                'provider' => 'git',
                'server' => $connection->host(),
                'method' => $connection->auth_method->value,
            ]));

            if ($connection->allow_private_network) {
                $this->audit->handle(new AuditEntryData(AuditAction::GitConnectionPrivateNetworkAllowed, $actor, $connection->team_id, 'git_connection', $connection->public_id, ['server' => $connection->host()]));
            }

            return $connection;
        });
    }

    /**
     * @throws DomainRuleViolation
     */
    private function httpsUrl(string $input, bool $privateNetwork): string
    {
        $url = rtrim($input, '/');
        $pattern = $privateNetwork ? '#^https?://[a-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~-]+)+$#D' : '#^https://[a-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~-]+)+$#D';

        if (preg_match($pattern, $url) !== 1 || str_contains($url, '/../') || str_ends_with($url, '/..')) {
            throw DomainRuleViolation::on('url', 'Enter an SSH address (git@host:group/app.git) or an https:// clone URL.');
        }

        return $url;
    }

    private function httpsName(string $url): string
    {
        return preg_replace('#^https?://#', '', (string) preg_replace('#\.git$#', '', $url)) ?? $url;
    }
}
