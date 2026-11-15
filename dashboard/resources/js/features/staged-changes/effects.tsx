import { Circle, Hammer, Minus, Plus, RotateCw, Waypoints } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

type Effect = App.Enums.ChangeEffect;

/**
 * How each staged change effect (PHP ChangeEffect, docs/06 §4) reads
 * everywhere: the one-word canvas chip (design-system §3.3), the badge
 * label and icon, and what the service bar says will happen.
 */
export const EFFECTS: Record<
    Effect,
    {
        chip: string;
        label: string;
        icon: LucideIcon;
        consequence: string | null;
    }
> = {
    new: {
        chip: 'New',
        label: 'new',
        icon: Plus,
        consequence: 'starts when applied',
    },
    remove: {
        chip: 'Removed',
        label: 'remove',
        icon: Minus,
        consequence: 'is removed when applied',
    },
    rebuild: {
        chip: 'Changed',
        label: 'rebuild',
        icon: Hammer,
        consequence: 'will rebuild',
    },
    restart: {
        chip: 'Changed',
        label: 'restart',
        icon: RotateCw,
        consequence: 'will restart',
    },
    route: {
        chip: 'Changed',
        label: 'routing',
        icon: Waypoints,
        consequence: 'changes routing',
    },
    none: {
        chip: 'Changed',
        label: 'no restart',
        icon: Circle,
        consequence: null,
    },
};

export function effectLabel(effect: Effect): string {
    return EFFECTS[effect].label;
}

/** The staged chip on canvas nodes and the panel header. */
export function StagedChip({ effect }: { effect: Effect }) {
    return (
        <span className="inline-flex h-5 shrink-0 items-center rounded-full border border-dashed border-signal bg-signal-soft px-2 type-small text-signal">
            {EFFECTS[effect].chip}
            <span className="sr-only"> (staged)</span>
        </span>
    );
}
