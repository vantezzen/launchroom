<?php

declare(strict_types=1);

namespace App\Support\Promotions;

use App\Data\Promotions\PromotionEntry;
use App\Data\Promotions\PromotionItem;
use App\Data\Promotions\PromotionSnapshot;
use App\Enums\ChangeEffect;
use App\Enums\ChangeKind;
use App\Enums\PromotionClass;
use App\Enums\PromotionEntryType;

/**
 * The three-way diff of Compare & promote (docs/06 §6.3). Pure: snapshots in,
 * items out.
 *
 * - base: the common state of the two environments at their last promotion
 *   (or when one was created from the other); null when they never met, then
 *   the target stands in for it (a two-way diff without conflicts);
 * - source: what is promoted; target: what receives it.
 *
 * Only changes made in the source since the base are offered. Where the target
 * changed the same item differently since the base, the item is a conflict
 * (unchecked: keeping the target's value is the default). Defaults follow the
 * promotion classes (docs/06 §6.2): structural items are checked, removals of
 * services and environment-specific items are not, secret values need a value
 * for the target, generated values are generated afresh, volume data and
 * domains never move.
 */
final class PromotionDiff
{
    /**
     * @return list<PromotionItem>
     */
    public static function between(?PromotionSnapshot $base, PromotionSnapshot $source, PromotionSnapshot $target): array
    {
        $paths = array_keys([...$source->entries, ...($base->entries ?? []), ...$target->entries]);
        $items = [];

        foreach ($paths as $path) {
            $s = $source->get($path);
            $t = $target->get($path);
            $b = $base instanceof PromotionSnapshot ? $base->get($path) : $t;

            $item = self::item($path, $b, $s, $t, $base instanceof PromotionSnapshot);

            if ($item instanceof PromotionItem) {
                $items[$path] = $item;
            }
        }

        $items = self::attachToServices($items, $source, $target);

        // Services first (by id), then their items, then project-level ones.
        uksort($items, static fn (string $a, string $b): int => [self::rank($a), $a] <=> [self::rank($b), $b]);

        return array_values($items);
    }

    /**
     * Count of what the dialog would offer (the switcher hint).
     *
     * @param  list<PromotionItem>  $items
     */
    public static function offered(array $items): int
    {
        return count(array_filter($items, static fn (PromotionItem $item): bool => $item->selectable));
    }

    private static function item(string $path, ?PromotionEntry $b, ?PromotionEntry $s, ?PromotionEntry $t, bool $hasBase): ?PromotionItem
    {
        if (self::same($s, $b) || self::same($s, $t)) {
            // Unchanged in the source since the base, or already in the target.
            return null;
        }

        // The source changed it; did the target too?
        $conflict = $hasBase && ! self::same($t, $b);
        $entry = $s ?? $t ?? $b;

        if (! $entry instanceof PromotionEntry) {
            return null;
        }

        $kind = match (true) {
            ! $t instanceof PromotionEntry => ChangeKind::Added,
            ! $s instanceof PromotionEntry => ChangeKind::Removed,
            default => ChangeKind::Changed,
        };

        return self::describe($path, $entry, $kind, $b, $s, $t, $conflict);
    }

    private static function describe(string $path, PromotionEntry $entry, ChangeKind $kind, ?PromotionEntry $b, ?PromotionEntry $s, ?PromotionEntry $t, bool $conflict): PromotionItem
    {
        $promotion = $entry->promotion;
        $effect = $entry->effect;
        $selectable = true;
        $needsValue = false;
        $acceptsValue = false;
        $warning = null;
        $note = null;

        switch ($entry->type) {
            case PromotionEntryType::Service:
                $promotion = PromotionClass::Structural;
                $effect = $kind === ChangeKind::Removed ? ChangeEffect::Remove : ChangeEffect::New;

                if ($kind === ChangeKind::Removed) {
                    $warning = $entry->holdsData ? 'Holds data: its volumes stay on the server, but the service stops in the target.' : null;
                } elseif ($kind === ChangeKind::Added) {
                    $note = $entry->holdsData ? 'A fresh instance with new credentials and empty data.' : null;
                }

                break;

            case PromotionEntryType::Variable:
            case PromotionEntryType::SharedVariable:
                $secretLike = $entry->secret || ($entry->generated && $entry->generator === null);
                $valueOnly = $s instanceof PromotionEntry && $t instanceof PromotionEntry
                    && self::withoutValue($s) === self::withoutValue($t);

                if ($kind === ChangeKind::Added && $entry->generated && $entry->generator !== null) {
                    $promotion = PromotionClass::Structural;
                    $note = 'Generated afresh for the target.';
                } elseif ($kind === ChangeKind::Added && $secretLike) {
                    $promotion = PromotionClass::Never;
                    $needsValue = true;
                    $acceptsValue = true;
                } elseif ($kind === ChangeKind::Changed && $valueOnly && ($secretLike || $entry->generated)) {
                    $promotion = PromotionClass::Never;
                    $acceptsValue = $secretLike;
                    $needsValue = $secretLike;
                    $selectable = $secretLike;
                    $note = $secretLike ? 'The value changed in the source. Enter the value for the target, or leave it unchecked.' : 'Generated values are never promoted.';
                } elseif ($kind === ChangeKind::Changed && $secretLike) {
                    // Type or exposure changed: the value stays unless a new one is entered.
                    $promotion = PromotionClass::Structural;
                    $acceptsValue = true;
                } else {
                    $promotion = PromotionClass::Structural;
                }

                break;

            case PromotionEntryType::Volume:
                if ($kind !== ChangeKind::Added) {
                    $selectable = false;
                    $note = 'Volumes are never removed or moved by a promotion; change them in the target’s settings.';
                } else {
                    $note = 'A new empty volume: data is never promoted.';
                }

                break;

            case PromotionEntryType::Connection:
                $selectable = false;
                $note = 'Follows the services: it applies wherever both services exist.';

                break;

            default:
                break;
        }

        $selected = $selectable && ! $conflict && match (true) {
            $entry->type === PromotionEntryType::Service && $kind === ChangeKind::Removed => false,
            $promotion === PromotionClass::EnvironmentSpecific => false,
            $promotion === PromotionClass::Never => $kind === ChangeKind::Added,
            default => true,
        };

        return new PromotionItem(
            path: $path,
            type: $entry->type,
            kind: $kind,
            promotion: $promotion,
            effect: $effect,
            label: $entry->label,
            serviceId: $entry->serviceId,
            key: $entry->key,
            before: $t?->display,
            after: $s?->display,
            base: $b?->display,
            conflict: $conflict,
            selectable: $selectable,
            selected: $selected,
            secret: $entry->secret,
            needsValue: $needsValue,
            acceptsValue: $acceptsValue,
            canCopyValue: $acceptsValue && $s instanceof PromotionEntry,
            copyBlockedReason: null,
            warning: $warning,
            note: $note,
            dependsOn: $entry->type === PromotionEntryType::Connection ? $entry->dependsOn : [],
        );
    }

    /**
     * Items of services the target doesn't have: settings and volumes come
     * with the service (copied from the source), variables and shared
     * variable usages depend on it; nothing of a service being removed is
     * listed separately. Usages depend on their shared variable.
     *
     * @param  array<string, PromotionItem>  $items
     * @return array<string, PromotionItem>
     */
    private static function attachToServices(array $items, PromotionSnapshot $source, PromotionSnapshot $target): array
    {
        foreach ($items as $path => $item) {
            if ($item->type === PromotionEntryType::Service || $item->serviceId === null) {
                continue;
            }

            $servicePath = 'services.'.$item->serviceId;

            if ($item->type === PromotionEntryType::Connection) {
                // Offered only as a consequence of a service being added or removed.
                $item->dependsOn = array_values(array_filter($item->dependsOn, static fn (string $dependency): bool => isset($items[$dependency])));

                if ($item->dependsOn === []) {
                    unset($items[$path]);
                }

                continue;
            }

            if ($target->get($servicePath) instanceof PromotionEntry) {
                if (! $source->get($servicePath) instanceof PromotionEntry) {
                    // The source no longer has the service: only its removal is offered.
                    unset($items[$path]);
                }

                continue;
            }

            $serviceItem = $items[$servicePath] ?? null;

            if (! $serviceItem instanceof PromotionItem || $serviceItem->kind !== ChangeKind::Added || in_array($item->type, [PromotionEntryType::Field, PromotionEntryType::Volume], true) || $item->kind !== ChangeKind::Added) {
                unset($items[$path]);

                continue;
            }

            $item->dependsOn = [$servicePath];
            $item->selected = $item->selected && $serviceItem->selected;
        }

        foreach ($items as $item) {
            if ($item->type !== PromotionEntryType::SharedUsage || $item->kind !== ChangeKind::Added) {
                continue;
            }

            $sharedPath = 'sharedVariables.'.$item->key;

            if (! $target->get($sharedPath) instanceof PromotionEntry) {
                if (! isset($items[$sharedPath])) {
                    unset($items[$item->path]);

                    continue;
                }

                $item->dependsOn = [...$item->dependsOn, $sharedPath];
            }
        }

        return $items;
    }

    private static function same(?PromotionEntry $a, ?PromotionEntry $b): bool
    {
        if (! $a instanceof PromotionEntry || ! $b instanceof PromotionEntry) {
            return $a === $b;
        }

        return $a->fingerprint === $b->fingerprint;
    }

    private static function withoutValue(PromotionEntry $entry): string
    {
        return $entry->type->value.'|'.($entry->secret ? 'secret' : 'config').'|'.$entry->effect->value;
    }

    private static function rank(string $path): int
    {
        return match (true) {
            str_starts_with($path, 'services.') => 0,
            str_starts_with($path, 'connections.') => 1,
            str_starts_with($path, 'sharedVariables.') => 2,
            default => 3,
        };
    }
}
