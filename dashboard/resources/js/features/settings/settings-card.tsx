import { usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Section } from '@/components/patterns/section';
import { cn } from '@/lib/utils';

/**
 * Settings are rows (design-system §1.2, direction §5.12): a titled block
 * whose controls sit in one card, separated by hairlines. Use it for every
 * settings page (team, instance, personal) and the settings-like parts of
 * the server page, so they all read the same.
 */
export function SettingsCard({
    title,
    description,
    actions,
    footer,
    children,
    className,
    id,
    setting,
}: {
    title?: ReactNode;
    /** One sentence, or a short qualifier. */
    description?: ReactNode;
    actions?: ReactNode;
    /** Quiet strip under the rows (a hint, a secondary action). */
    footer?: ReactNode;
    children: ReactNode;
    className?: string;
    id?: string;
    /** Search anchor (service settings, `settings-index.ts`). */
    setting?: string;
}) {
    return (
        <Section
            id={id}
            data-setting={setting}
            title={title}
            description={description}
            actions={actions}
            className={className}
        >
            <div className="overflow-hidden rounded-xl border bg-card shadow-card">
                <div className="divide-y">{children}</div>
                {footer && (
                    <div className="border-t bg-recessed px-5 py-3 type-small text-muted-foreground">
                        {footer}
                    </div>
                )}
            </div>
        </Section>
    );
}

/**
 * One setting: what it is (label + one quiet line) on the left, its control
 * or value on the right. Stacks on phones.
 */
export function SettingsRow({
    label,
    description,
    media,
    children,
    htmlFor,
    align = 'center',
    inline = false,
    className,
    setting,
}: {
    label: ReactNode;
    description?: ReactNode;
    /** Icon, logo or avatar before the label. */
    media?: ReactNode;
    /** The control or value. */
    children?: ReactNode;
    /** Makes the label a `<label>` for this control id. */
    htmlFor?: string;
    align?: 'center' | 'start';
    /** Keep the control beside the label on phones too (chips, `⋯` menus). */
    inline?: boolean;
    className?: string;
    /** Search anchor (service settings, `settings-index.ts`). */
    setting?: string;
}) {
    const Label = htmlFor ? 'label' : 'div';

    return (
        <div
            data-setting={setting}
            className={cn(
                'flex gap-3 px-5 py-4 sm:justify-between sm:gap-6',
                inline
                    ? 'flex-row items-center justify-between'
                    : 'flex-col sm:flex-row',
                align === 'center' ? 'sm:items-center' : 'sm:items-start',
                className,
            )}
        >
            <div className="flex min-w-0 items-center gap-3">
                {media && <div className="shrink-0">{media}</div>}
                <div className="flex min-w-0 flex-col gap-0.5">
                    <Label
                        htmlFor={htmlFor}
                        className="type-body font-medium text-foreground"
                    >
                        {label}
                    </Label>
                    {description && (
                        <div className="type-small text-muted-foreground">
                            {description}
                        </div>
                    )}
                </div>
            </div>
            {children !== undefined && (
                <div className="flex min-w-0 shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                    {children}
                </div>
            )}
        </div>
    );
}

/** A block inside a settings card that isn't a row (a form, a list). */
export function SettingsBody({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return <div className={cn('px-5 py-5', className)}>{children}</div>;
}

/** "Learn more ↗": where long explanations go instead of prose. */
export function LearnMore({
    href,
    children = 'Learn more',
    className,
}: {
    href: string;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer"
            className={cn(
                'inline-flex items-center gap-0.5 text-signal underline decoration-signal/40 underline-offset-4 hover:decoration-signal',
                className,
            )}
        >
            {children}
            <ArrowUpRight className="size-3.5" aria-hidden="true" />
        </a>
    );
}

/**
 * Public user docs ("Learn more" targets, docs/21): the base URL comes from
 * the `docsUrl` page prop (config launchroom.docs_url), pages by path:
 * `const docs = useDocsUrl(); docs('servers/home-machines')`.
 */
export function useDocsUrl(): (page: string) => string {
    const base = usePage().props.docsUrl.replace(/\/+$/, '');

    return (page: string) => `${base}/${page.replace(/^\/+/, '')}`;
}
