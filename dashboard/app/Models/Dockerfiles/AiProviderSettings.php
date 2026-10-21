<?php

declare(strict_types=1);

namespace App\Models\Dockerfiles;

use App\Enums\Dockerfiles\AiProvider;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The instance's model provider for Dockerfile generation (docs/20 §1),
 * singleton id = 1. The API key only exists envelope encrypted
 * (App\Services\Dockerfiles\AiProviderSecrets); `api_key_last4` is the
 * only part shown.
 *
 * @property int $id
 * @property bool $enabled
 * @property AiProvider $provider
 * @property string|null $model null = `launchroom.dockerfile_generation.default_model`
 * @property string|null $api_key_ciphertext
 * @property string|null $wrapped_dek
 * @property string|null $kek_id
 * @property string|null $api_key_last4
 * @property int|null $updated_by
 * @property Carbon|null $key_set_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['api_key_ciphertext', 'wrapped_dek'])]
#[Table(name: 'ai_provider_settings')]
#[WithoutIncrementing]
final class AiProviderSettings extends Model
{
    public const int ID = 1;

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => false,
        'provider' => 'anthropic',
    ];

    public static function current(): self
    {
        self::query()->insertOrIgnore(['id' => self::ID, 'created_at' => now(), 'updated_at' => now()]);

        return self::query()->findOrFail(self::ID);
    }

    public static function lockedForUpdate(): self
    {
        self::query()->insertOrIgnore(['id' => self::ID, 'created_at' => now(), 'updated_at' => now()]);

        return self::query()->whereKey(self::ID)->lockForUpdate()->firstOrFail();
    }

    public function hasKey(): bool
    {
        return $this->api_key_ciphertext !== null && $this->wrapped_dek !== null;
    }

    public function effectiveModel(): string
    {
        return $this->model ?? config()->string('launchroom.dockerfile_generation.default_model');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'provider' => AiProvider::class,
            'key_set_at' => 'datetime',
        ];
    }
}
