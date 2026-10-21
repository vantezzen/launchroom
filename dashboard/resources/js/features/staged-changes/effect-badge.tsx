import { Circle, Hammer, Minus, Plus, RotateCw, Waypoints } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { effectLabel } from './use-staged-changes';

const effectIcons: Record<App.Enums.ChangeEffect, ReactNode> = {
    new: <Plus aria-hidden="true" />,
    remove: <Minus aria-hidden="true" />,
    rebuild: <Hammer aria-hidden="true" />,
    restart: <RotateCw aria-hidden="true" />,
    route: <Waypoints aria-hidden="true" />,
    none: <Circle aria-hidden="true" />,
};

/**
 * What a change needs when deployed (docs/06 §4): icon plus label.
 */
export function EffectBadge({ effect }: { effect: App.Enums.ChangeEffect }) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'gap-1 font-normal',
                effect === 'remove' && 'text-status-error',
            )}
        >
            {effectIcons[effect]}
            {effectLabel(effect)}
        </Badge>
    );
}

/**
 * Settings values arrive as canonical JSON; show strings without quotes.
 */
export function displayValue(value: string | null): string {
    if (value === null) {
        return '';
    }

    try {
        const parsed: unknown = JSON.parse(value);

        if (typeof parsed === 'string') {
            return parsed;
        }

        if (parsed === null) {
            return 'default';
        }

        if (
            Array.isArray(parsed) &&
            parsed.every((part) => typeof part === 'string')
        ) {
            return parsed.join(' ');
        }
    } catch {
        // Not JSON: as is.
    }

    return value;
}
