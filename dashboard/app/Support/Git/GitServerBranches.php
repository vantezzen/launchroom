<?php

declare(strict_types=1);

namespace App\Support\Git;

/**
 * Branch facts from a plain git server's ref advertisement (docs/19 §4.2).
 */
final class GitServerBranches
{
    /**
     * The default branch: the branch HEAD points at (same commit; `main`,
     * then `master` win ties), else `main`/`master` when they exist, else the
     * first branch.
     *
     * @param  array<string, string>  $refs
     */
    public static function defaultBranch(array $refs): ?string
    {
        $branches = [];

        foreach ($refs as $ref => $sha) {
            if (str_starts_with($ref, 'refs/heads/')) {
                $branches[substr($ref, 11)] = $sha;
            }
        }

        if ($branches === []) {
            return null;
        }

        $head = $refs['HEAD'] ?? null;
        $candidates = $head === null ? $branches : array_filter($branches, static fn (string $sha): bool => $sha === $head);
        $candidates = $candidates === [] ? $branches : $candidates;

        foreach (['main', 'master'] as $preferred) {
            if (isset($candidates[$preferred])) {
                return $preferred;
            }
        }

        return array_key_first($candidates);
    }
}
