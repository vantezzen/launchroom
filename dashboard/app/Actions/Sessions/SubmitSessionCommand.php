<?php

declare(strict_types=1);

namespace App\Actions\Sessions;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Sessions\SessionCommandData;
use App\Enums\AuditAction;
use App\Enums\Sessions\DatabaseQueryStatus;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Enums\Sessions\InteractiveSessionStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Sessions\DatabaseQuery;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Sessions\SessionRelay;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sends one Data tab command to the database's server as a signed
 * SessionCommand (docs/03 §11.2). Statements are audited **before** they are
 * sent (verb, length, hash, read-only or writes allowed; never the text) and
 * kept in the user's history encrypted under the project DEK (docs/09 R33).
 */
final readonly class SubmitSessionCommand
{
    public function __construct(
        private SessionRelay $relay,
        private RecordAuditEvent $audit,
        private ProjectKeyring $keyring,
    ) {}

    /**
     * @return string the op id the results will carry
     */
    public function handle(User $user, InteractiveSession $session, SessionCommandData $command, AuditActor $actor): string
    {
        if ($session->kind !== InteractiveSessionKind::Data || $session->user_id !== $user->id) {
            throw DomainRuleViolation::forbidden('Only the person who opened this session can use it.');
        }

        if ($session->status !== InteractiveSessionStatus::Open) {
            throw DomainRuleViolation::conflict($session->status === InteractiveSessionStatus::Ended
                ? 'This session has ended. Open a new one.'
                : 'Still connecting to the server…');
        }

        $op = PublicId::generate('op');

        if ($command->command === 'query') {
            DB::transaction(function () use ($user, $session, $command, $actor, $op): void {
                $session->loadMissing('project', 'serviceInstance.service');
                $statement = is_string($command->statement) ? $command->statement : '';
                $query = new DatabaseQuery([
                    'team_id' => $session->team_id,
                    'interactive_session_id' => $session->id,
                    'service_instance_id' => $session->service_instance_id,
                    'user_id' => $user->id,
                    'op_id' => $op,
                    'statement_hash' => hash('sha256', $statement),
                    'verb' => $command->verb(),
                    'allow_writes' => $command->allowWrites === true,
                ]);
                $query->public_id = PublicId::generate(DatabaseQuery::publicIdPrefix());
                $query->statement = $this->keyring->encryptQuery($session->project, $query->public_id, $statement);
                $query->save();

                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::DatabaseStatementExecuted,
                    actor: $actor,
                    teamId: $session->team_id,
                    subjectType: 'service',
                    subjectId: $session->serviceInstance->service->public_id,
                    meta: [
                        'session' => $session->public_id,
                        'op' => $op,
                        'query' => $query->public_id,
                        'verb' => $query->verb,
                        'bytes' => strlen($statement),
                        'sha256' => $query->statement_hash,
                        'writes' => $query->allow_writes,
                    ],
                ));
            });
        }

        try {
            return $this->relay->send($session, $command, $op);
        } catch (RuntimeException $e) {
            DatabaseQuery::query()->where('op_id', $op)->first()?->finish(DatabaseQueryStatus::Error);

            throw DomainRuleViolation::conflict($e->getMessage());
        }
    }
}
