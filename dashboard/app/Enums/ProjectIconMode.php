<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a project's icon comes from (docs/08 §13): the deployed app's
 * favicon, an uploaded image, or none (the monogram).
 */
enum ProjectIconMode: string
{
    case Automatic = 'automatic';
    case Uploaded = 'uploaded';
    case None = 'none';
}
