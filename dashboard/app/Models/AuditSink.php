<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Audit\AuditSinkKind;
use App\Enums\Audit\AuditSinkStatus;
use App\Models\Concerns\HasPublicId;
use App\Services\Audit\AuditSinkSecrets;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * An instance-wide destination for the audit log (docs/09 R34, docs/07
 * `audit_sinks`). Credentials (webhook URL and signing secret, S3 keys) are
 * envelope-encrypted in `secret` and only opened by {@see AuditSinkSecrets};
 * `config` holds the non-secret settings and `target_hint` the one
 * clear-text trace of a webhook URL (host only).
 *
 * @property int $id
 * @property string $public_id
 * @property AuditSinkKind $kind
 * @property string $name
 * @property array<string, mixed> $config
 * @property string|null $target_hint
 * @property string|null $wrapped_dek
 * @property string|null $kek_id
 * @property string|null $secret
 * @property bool $allow_private_network
 * @property bool $enabled
 * @property int $cursor_event_id
 * @property CarbonImmutable|null $delivered_through_at
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $last_success_at
 * @property string|null $last_error
 * @property int $failures
 * @property CarbonImmutable|null $next_attempt_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['kind', 'name', 'config', 'target_hint', 'allow_private_network', 'enabled', 'created_by'])]
#[Hidden(['secret', 'wrapped_dek', 'kek_id'])]
final class AuditSink extends Model
{
    use HasPublicId;

    public const int MAX_SINKS = 10;

    /** @var array<string, mixed> */
    protected $attributes = [
        'config' => '{}',
        'enabled' => true,
        'allow_private_network' => false,
        'cursor_event_id' => 0,
        'failures' => 0,
    ];

    public static function publicIdPrefix(): string
    {
        return 'aus';
    }

    public function status(): AuditSinkStatus
    {
        return match (true) {
            ! $this->enabled => AuditSinkStatus::Paused,
            $this->failures > 0 => AuditSinkStatus::Failing,
            $this->last_success_at === null => AuditSinkStatus::Pending,
            default => AuditSinkStatus::Delivering,
        };
    }

    public function configString(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function configInt(string $key): ?int
    {
        $value = $this->config[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AuditSinkKind::class,
            'config' => 'array',
            'allow_private_network' => 'boolean',
            'enabled' => 'boolean',
            'cursor_event_id' => 'integer',
            'failures' => 'integer',
            'delivered_through_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
