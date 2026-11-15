<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Crypto\SealedRow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * A project's data-encryption key, wrapped by the KEK (docs/09 R13).
 *
 * @property int $id
 * @property int $project_id
 * @property int $version
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'version', 'wrapped_dek', 'kek_id'])]
#[Hidden(['wrapped_dek'])]
final class ProjectKey extends Model implements SealedRow
{
    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function sealWrapContext(): string
    {
        return 'project:'.($this->project ?? throw new LogicException('A project key needs its project.'))->public_id;
    }

    public function sealAssociatedData(string $column): string
    {
        throw new InvalidArgumentException("{$column} is not a sealed column.");
    }

    /**
     * @return Builder<self>
     */
    public static function sealedRows(): Builder
    {
        return self::query()->with('project');
    }
}
