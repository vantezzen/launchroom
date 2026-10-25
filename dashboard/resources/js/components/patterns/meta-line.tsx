import { Children, Fragment, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A 1 px vertical hairline between two inline facts (design-system §13.3).
 * Screen readers hear a comma instead.
 */
export function MetaDivider({ className }: { className?: string }) {
    return (
        <>
            <span
                aria-hidden="true"
                className={cn(
                    'inline-block h-3 w-px shrink-0 self-center bg-border align-middle',
                    className,
                )}
            />
            <span className="sr-only">, </span>
        </>
    );
}

type MetaLineProps = {
    /** The facts, in reading order. Empty values (null, false, '') are skipped. */
    children?: ReactNode;
    className?: string;
};

/**
 * One line of quiet facts ("3b7d5e1 | main | jonas | 2m ago") separated by
 * hairline dividers instead of a "·" character. Wraps on narrow screens.
 */
export function MetaLine({ children, className }: MetaLineProps) {
    // toArray already drops null, undefined and booleans.
    const items = Children.toArray(children).filter((child) => child !== '');

    return (
        <span
            className={cn(
                'inline-flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5',
                className,
            )}
        >
            {items.map((item, index) => (
                <Fragment key={index}>
                    {index > 0 && <MetaDivider />}
                    {typeof item === 'string' || typeof item === 'number' ? (
                        <span className="min-w-0">{item}</span>
                    ) : (
                        item
                    )}
                </Fragment>
            ))}
        </span>
    );
}
