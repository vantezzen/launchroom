<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a canvas connection reaches its target (docs/06 §3.2): over the
 * environment network or through the target's public URL.
 */
enum ConnectionMode: string
{
    case Private = 'private';
    case Public = 'public';
}
