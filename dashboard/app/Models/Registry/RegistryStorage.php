<?php

declare(strict_types=1);

namespace App\Models\Registry;

use App\Models\User;
use App\Services\Registry\RegistryStorageSecrets;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Zot's storage on an S3-compatible bucket (docs/14 §9.11). Singleton
 * (id = 1); no row = local storage on the control-plane disk. The
 * credentials are only ever read through {@see RegistryStorageSecrets}.
 *
 * @property int $id
 * @property string $endpoint
 * @property string $bucket
 * @property string|null $region
 * @property string $prefix
 * @property bool $force_path_style
 * @property bool $allow_private_network
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property string $credentials
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $editor
 */
#[Fillable(['endpoint', 'bucket', 'region', 'prefix', 'force_path_style', 'allow_private_network', 'updated_by'])]
#[Hidden(['wrapped_dek', 'kek_id', 'credentials'])]
#[Table(name: 'registry_storage')]
#[WithoutIncrementing]
final class RegistryStorage extends Model
{
    public const int ID = 1;

    /** @var array<string, mixed> */
    protected $attributes = [
        'prefix' => 'registry',
        'force_path_style' => true,
        'allow_private_network' => false,
    ];

    public static function current(): ?self
    {
        return self::query()->find(self::ID);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'force_path_style' => 'boolean',
            'allow_private_network' => 'boolean',
        ];
    }
}
