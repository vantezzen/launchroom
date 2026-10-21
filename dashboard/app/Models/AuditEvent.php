<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\ActorType;
use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One append-only, hash-chained audit entry (docs/07). Written only by
 * {@see RecordAuditEvent}; a database trigger rejects
 * UPDATE and DELETE.
 *
 * @property int $id
 * @property int|null $team_id
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property AuditAction $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed> $meta
 * @property string $prev_hash
 * @property string $hash
 * @property Carbon $created_at
 */
#[Fillable(['team_id', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'ip', 'user_agent', 'meta', 'prev_hash', 'hash', 'created_at'])]
#[DateFormat('Y-m-d H:i:s.uP')]
final class AuditEvent extends Model
{
    public const ?string UPDATED_AT = null;

    public static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Audit events are append-only.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Audit events are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'action' => AuditAction::class,
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
