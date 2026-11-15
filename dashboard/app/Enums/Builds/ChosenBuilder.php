<?php

declare(strict_types=1);

namespace App\Enums\Builds;

/**
 * The builder a plan or build used (BuildSummary.builder, docs/04 §3).
 */
enum ChosenBuilder: string
{
    case Dockerfile = 'dockerfile';
    case Railpack = 'railpack';
}
