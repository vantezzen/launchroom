<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Git\GitConnectionStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\Providers\PlainGitProvider;
use App\Support\Git\GitServerBranches;

/**
 * "These match, check access" (docs/19 §4.2): pins the host keys the user
 * checked (audited), then lists the repository's branches over SSH with the
 * deploy key. Success activates the connection; a refusal keeps it pending
 * with the reason, so the user can add the key and try again.
 */
final readonly class ConfirmGitServer
{
    public function __construct(
        private PlainGitProvider $plain,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<string>  $fingerprints  what the user saw (must equal the stored keys)
     *
     * @throws DomainRuleViolation
     */
    public function handle(GitConnection $connection, array $fingerprints, AuditActor $actor): GitConnection
    {
        $stored = array_map(static fn (array $key): string => $key['fingerprint'], $connection->host_keys ?? []);
        sort($stored);
        sort($fingerprints);

        if ($stored === [] || $stored !== $fingerprints) {
            throw DomainRuleViolation::on('fingerprints', 'The host keys changed while you were checking them. Check them again.');
        }

        if ($connection->host_keys_confirmed_at === null) {
            $connection->forceFill(['host_keys_confirmed_at' => now()])->save();
            $this->audit->handle(new AuditEntryData(AuditAction::GitHostKeysConfirmed, $actor, $connection->team_id, 'git_connection', $connection->public_id, [
                'server' => $connection->host(),
                'fingerprints' => $stored,
            ]));
        }

        try {
            $refs = $this->plain->refs($connection);
        } catch (GitConnectionUnusable $e) {
            $connection->forceFill(['status_reason' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw DomainRuleViolation::on('access', $e->getMessage());
        }

        GitRepository::query()
            ->where('git_connection_id', $connection->id)
            ->update(['default_branch' => GitServerBranches::defaultBranch($refs)]);

        if ($connection->status !== GitConnectionStatus::Active) {
            $connection->transitionTo(GitConnectionStatus::Active);
        }

        return $connection;
    }
}
