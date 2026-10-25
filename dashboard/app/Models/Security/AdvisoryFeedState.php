<?php

declare(strict_types=1);

namespace App\Models\Security;

use App\Enums\Security\AdvisoryFeed;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * State of one advisory source (docs/22 §4.4): the owner's switch, last
 * success and attempt, the error, conditional-GET validators and the OSV
 * sync's cursor and back-off.
 *
 * @property AdvisoryFeed $feed
 * @property bool $enabled
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $failing_since
 * @property string|null $last_error
 * @property string|null $etag
 * @property string|null $last_modified
 * @property int $items
 * @property array{after?: list<string>, full?: bool}|null $cursor
 * @property int $backoff_seconds
 * @property Carbon|null $retry_at
 * @property Carbon|null $manual_check_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(name: 'advisory_feeds', key: 'feed', keyType: 'string', incrementing: false)]
final class AdvisoryFeedState extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => true,
        'items' => 0,
        'backoff_seconds' => 0,
    ];

    public static function of(AdvisoryFeed $feed): self
    {
        $state = self::query()->find($feed->value);

        if ($state instanceof self) {
            return $state;
        }

        $state = new self;
        $state->feed = $feed;
        $state->save();

        return $state;
    }

    public static function enabled(AdvisoryFeed $feed): bool
    {
        return self::of($feed)->enabled;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feed' => AdvisoryFeed::class,
            'enabled' => 'boolean',
            'last_success_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'failing_since' => 'datetime',
            'items' => 'integer',
            'cursor' => 'array',
            'backoff_seconds' => 'integer',
            'retry_at' => 'datetime',
            'manual_check_at' => 'datetime',
        ];
    }
}
