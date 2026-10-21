<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only model exercising {@see HasPublicId} (table created in the test).
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 */
#[Unguarded]
#[Table(name: 'public_id_widgets')]
final class PublicIdWidget extends Model
{
    use HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'wdg';
    }
}
