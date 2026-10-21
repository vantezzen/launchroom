<?php

declare(strict_types=1);

namespace App\Models\Transfers;

use App\Enums\Transfers\TransferDirection;
use App\Enums\Transfers\TransferPurpose;
use App\Enums\Transfers\TransferStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Services\Transfers\TransferStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file moving through the dashboard in chunks (docs/14 §12.1, D55): an
 * upload to import, or an export to download. Chunks live on disk,
 * encrypted with this transfer's own data key ({@see TransferStore}).
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $service_instance_id
 * @property int|null $volume_id
 * @property TransferDirection $direction
 * @property TransferPurpose $purpose
 * @property TransferStatus $status
 * @property string $file_name display only
 * @property int $size_bytes declared (uploads) or written so far / final (downloads)
 * @property int $chunk_bytes
 * @property int $chunk_count
 * @property array<int, array{d: string, n: int}>|null $chunk_digests chunk index => SHA-256 (lowercase hex) and length
 * @property string|null $digest SHA-256 over the chunks' raw digests in order
 * @property string|null $format protocol DataFormat
 * @property bool $compressed
 * @property string|null $source_version "PostgreSQL 15.8"
 * @property string|null $detail human summary of what was detected
 * @property array<string, mixed>|null $sniff what the sniffer found (engine family, versions)
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property int|null $created_by
 * @property string|null $error
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_used_at
 * @property Carbon $expires_at
 * @property string|null $link_token_hash
 * @property int|null $link_user_id
 * @property Carbon|null $link_expires_at
 * @property int $downloads
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 * @property-read Volume|null $volume
 * @property-read User|null $creator
 */
#[Fillable(['team_id', 'service_instance_id', 'volume_id', 'direction', 'purpose', 'file_name', 'size_bytes', 'chunk_bytes', 'chunk_count', 'created_by', 'expires_at'])]
#[Hidden(['wrapped_dek', 'kek_id', 'link_token_hash', 'chunk_digests', 'sniff'])]
final class DataTransfer extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'receiving',
        'compressed' => false,
        'downloads' => 0,
    ];

    public static function publicIdPrefix(): string
    {
        return 'dtr';
    }

    /**
     * The only way a transfer's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(TransferStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to === TransferStatus::Ready) {
            $this->forceFill(['completed_at' => now()]);
        }

        if ($to->isTerminal()) {
            $this->error = $error === null ? null : mb_substr($error, 0, 2048);
            $this->link_token_hash = null;
            $this->link_expires_at = null;
        }

        $this->save();
    }

    /**
     * Indices of the chunks received so far, ascending.
     *
     * @return list<int>
     */
    public function receivedChunks(): array
    {
        $indices = array_map(intval(...), array_keys($this->chunk_digests ?? []));
        sort($indices);

        return $indices;
    }

    /**
     * The file's digest (docs/14 §12.1): SHA-256 over the raw SHA-256 of
     * chunks 0..$count-1 in order; null while one is missing.
     */
    public function digestOver(int $count): ?string
    {
        $chunks = $this->chunk_digests ?? [];
        $raw = '';

        for ($i = 0; $i < $count; $i++) {
            $digest = isset($chunks[$i]) ? $chunks[$i]['d'] : '';

            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                return null;
            }

            $raw .= (string) hex2bin($digest);
        }

        return hash('sha256', $raw);
    }

    /**
     * Total length of chunks 0..$count-1 as received.
     */
    public function lengthOver(int $count): int
    {
        $chunks = $this->chunk_digests ?? [];
        $total = 0;

        for ($i = 0; $i < $count; $i++) {
            $total += isset($chunks[$i]) ? $chunks[$i]['n'] : 0;
        }

        return $total;
    }

    /**
     * The byte length chunk $index must have.
     */
    public function expectedChunkLength(int $index): int
    {
        if ($index < $this->chunk_count - 1) {
            return $this->chunk_bytes;
        }

        return $this->size_bytes - ($this->chunk_count - 1) * $this->chunk_bytes;
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<Volume, $this>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => TransferDirection::class,
            'purpose' => TransferPurpose::class,
            'status' => TransferStatus::class,
            'size_bytes' => 'integer',
            'chunk_bytes' => 'integer',
            'chunk_count' => 'integer',
            'chunk_digests' => 'array',
            'sniff' => 'array',
            'compressed' => 'boolean',
            'downloads' => 'integer',
            'completed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'link_expires_at' => 'datetime',
        ];
    }
}
