<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A signed position of the audit chain (docs/09 R34, protocol/SIGNING.md
 * §7): entry `event_id` has `event_hash` and is the `event_count`-th entry.
 * Append-only (a database trigger rejects UPDATE and DELETE).
 *
 * @property int $id
 * @property int $event_id
 * @property string $event_hash
 * @property int $event_count
 * @property string|null $instance_id
 * @property string $kid
 * @property string $signature base64 Ed25519
 * @property CarbonImmutable $created_at
 */
#[Fillable(['event_id', 'event_hash', 'event_count', 'instance_id', 'kid', 'signature', 'created_at'])]
#[DateFormat('Y-m-d H:i:s.uP')]
final class AuditCheckpoint extends Model
{
    public const ?string UPDATED_AT = null;

    public static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Audit checkpoints are append-only.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Audit checkpoints are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'event_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
