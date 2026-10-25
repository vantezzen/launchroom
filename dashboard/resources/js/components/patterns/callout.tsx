import { CircleX, Info, TriangleAlert } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Tone = 'info' | 'warning' | 'error';

const icons = { info: Info, warning: TriangleAlert, error: CircleX } as const;

const iconTone: Record<Tone, string> = {
    info: 'text-muted-foreground',
    warning: 'text-status-progress',
    error: 'text-status-error',
};

/** Read before the title, so the tone isn't carried by colour alone. */
const srPrefix: Record<Tone, string> = {
    info: '',
    warning: 'Warning: ',
    error: 'Error: ',
};

/**
 * A callout (design-system §6): a bordered card with a 28 px recessed icon
 * tile, one bold line, one or two sentences, optional steps and actions.
 * Amber only for something that needs action, red only when it's broken;
 * the frame stays neutral either way.
 */
export function Callout({
    tone = 'info',
    title,
    icon,
    actions,
    children,
    className,
    ...props
}: Omit<ComponentProps<'div'>, 'title'> & {
    tone?: Tone;
    title: ReactNode;
    /** Replaces the tone's icon (a lucide icon element). */
    icon?: ReactNode;
    actions?: ReactNode;
    children?: ReactNode;
}) {
    const Icon = icons[tone];

    return (
        <div
            data-slot="callout"
            className={cn(
                'flex min-w-0 items-start gap-3.5 rounded-xl border bg-card px-4 py-3.5',
                className,
            )}
            {...props}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'flex size-7 shrink-0 items-center justify-center rounded-lg bg-recessed [&>svg]:size-3.5',
                    iconTone[tone],
                )}
            >
                {icon ?? <Icon />}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="type-body font-medium text-foreground">
                    {srPrefix[tone] && (
                        <span className="sr-only">{srPrefix[tone]}</span>
                    )}
                    {title}
                </p>
                {children && (
                    <div className="flex min-w-0 flex-col gap-2 type-small [overflow-wrap:anywhere] text-muted-foreground">
                        {children}
                    </div>
                )}
                {actions && (
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 pt-1 type-small">
                        {actions}
                    </div>
                )}
            </div>
        </div>
    );
}
