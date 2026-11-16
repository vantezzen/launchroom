<?php

declare(strict_types=1);

namespace App\Support\Changes;

use App\Data\Specs\ChangeItem;

/**
 * How much is staged for one service, in words for its canvas node
 * (docs/08 §13): "2 variables", "1 variable and 3 settings", "5 changes".
 * Adding or removing the whole service isn't counted: the node's chip
 * says New or Removed.
 */
final class ChangeTally
{
    /** Kinds in the order they're named, with singular and plural. */
    private const array KINDS = [
        'variable' => ['variable', 'variables'],
        'domain' => ['domain', 'domains'],
        'volume' => ['volume', 'volumes'],
        'connection' => ['connection', 'connections'],
        'setting' => ['setting', 'settings'],
    ];

    /**
     * @param  list<ChangeItem>  $items  the service's staged items
     */
    public static function summary(array $items): ?string
    {
        $counts = [];

        foreach ($items as $item) {
            $kind = self::kind($item->path);

            if ($kind !== null) {
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        $total = array_sum($counts);

        if (count($counts) > 2) {
            return "{$total} changes";
        }

        $parts = [];

        foreach (self::KINDS as $kind => [$one, $many]) {
            if (isset($counts[$kind])) {
                $parts[] = $counts[$kind].' '.($counts[$kind] === 1 ? $one : $many);
            }
        }

        return implode(' and ', $parts);
    }

    private static function kind(string $path): ?string
    {
        if (str_starts_with($path, 'connections.')) {
            return 'connection';
        }

        $parts = explode('.', $path);

        // `services.{id}` alone: the service is added or removed.
        if (count($parts) < 3) {
            return null;
        }

        return match ($parts[2]) {
            'variables', 'connectionVariables', 'sharedVariables' => 'variable',
            'domains' => 'domain',
            'volumes' => 'volume',
            default => 'setting',
        };
    }
}
