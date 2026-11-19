<?php

declare(strict_types=1);

namespace App\Models\Traces;

use App\Contracts\Crypto\SealedRow;
use App\Models\Concerns\HasSealedSecret;
use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * An edge server's request trace key (docs/23 §4): AES-256, envelope
 * encrypted (docs/09 R13). The id is the key id in the trace header.
 * Written only by App\Actions\Traces\ProvisionTraceKeys; it leaves the
 * dashboard only sealed to its edge (IngressSecret purpose `trace`).
 *
 * @property int $id
 * @property int $server_id
 * @property string $ciphertext
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $retired_at
 * @property-read Server $server
 */
#[Hidden(['ciphertext', 'wrapped_dek', 'kek_id'])]
#[WithoutIncrementing]
final class TraceKey extends Model implements SealedRow
{
    use HasSealedSecret;

    /** Largest key id: the header has 4 bytes for it. */
    public const int MAX_ID = 4294967295;

    public const ?string UPDATED_AT = null;

    protected $keyType = 'int';

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function sealWrapContext(): string
    {
        return "trace_key:{$this->id}:{$this->server_id}";
    }

    public function sealAssociatedData(string $column): string
    {
        return match ($column) {
            'ciphertext' => "trace_key|{$this->id}|{$this->server_id}",
            default => throw new InvalidArgumentException("{$column} is not a sealed column."),
        };
    }
}
