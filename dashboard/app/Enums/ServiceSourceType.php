<?php

declare(strict_types=1);

namespace App\Enums;

use App\Data\Services\DatabaseSource;
use App\Data\Services\GitSource;
use App\Data\Services\ImageSource;

/**
 * The `type` discriminator of a stored service source (`services.source`,
 * revision specs). Values are stored: never rename a case value.
 */
enum ServiceSourceType: string
{
    case Git = 'git';
    case Image = 'image';
    case Database = 'database';

    /**
     * @return class-string<GitSource|ImageSource|DatabaseSource>
     */
    public function sourceClass(): string
    {
        return match ($this) {
            self::Git => GitSource::class,
            self::Image => ImageSource::class,
            self::Database => DatabaseSource::class,
        };
    }
}
