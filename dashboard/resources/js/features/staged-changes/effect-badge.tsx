import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { EFFECTS } from './effects';

/**
 * What a change needs when deployed (docs/06 §4): icon plus label.
 */
export function EffectBadge({ effect }: { effect: App.Enums.ChangeEffect }) {
    const { icon: Icon, label } = EFFECTS[effect];

    return (
        <Badge
            variant="outline"
            className={cn(
                'gap-1 font-normal',
                effect === 'remove' && 'text-status-error',
            )}
        >
            <Icon aria-hidden="true" />
            {label}
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
