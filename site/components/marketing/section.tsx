import Link from 'next/link';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export function Container({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('mx-auto w-full max-w-[1200px] px-4 sm:px-6', className)}>{children}</div>;
}

export function Section({
    id,
    className,
    children,
    tone = 'plain',
    labelledBy,
}: {
    id?: string;
    className?: string;
    children: ReactNode;
    tone?: 'plain' | 'frame';
    labelledBy?: string;
}) {
    return (
        <section
            id={id}
            aria-labelledby={labelledBy}
            className={cn(
                'scroll-mt-16 py-20 sm:py-28',
                tone === 'frame' && 'border-y border-border bg-frame',
                className,
            )}
        >
            <Container>{children}</Container>
        </section>
    );
}

/** Section heading: a short label, the headline and one or two sentences. */
export function SectionHeading({
    id,
    label,
    title,
    children,
    align = 'left',
    className,
}: {
    id?: string;
    label?: string;
    title: ReactNode;
    children?: ReactNode;
    align?: 'left' | 'center';
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex max-w-[640px] flex-col gap-4',
                align === 'center' && 'mx-auto items-center text-center',
                className,
            )}
        >
            {label ? <p className="type-label text-muted-foreground">{label}</p> : null}
            <h2 id={id} className="type-section text-balance text-foreground">
                {title}
            </h2>
            {children ? <div className="type-lead text-pretty text-muted-foreground">{children}</div> : null}
        </div>
    );
}

export function MoreLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link
            href={href}
            className="group inline-flex w-fit items-center gap-1.5 text-sm font-medium text-foreground underline decoration-border-strong underline-offset-4 hover:decoration-foreground"
        >
            {children}
            <ArrowRight
                className="size-3.5 transition-transform duration-150 group-hover:translate-x-0.5"
                aria-hidden="true"
            />
        </Link>
    );
}

/** A feature row: copy on one side, a visual on the other. */
export function FeatureRow({
    id,
    label,
    title,
    children,
    visual,
    reverse = false,
    wide = false,
    points,
    more,
}: {
    id: string;
    label: string;
    title: string;
    children: ReactNode;
    visual: ReactNode;
    reverse?: boolean;
    /** Copy on top (heading left, points right), the visual full width below. */
    wide?: boolean;
    points?: string[];
    more?: { href: string; text: string };
}) {
    const pointList = points ? (
        <ul className="flex flex-col gap-2.5 text-[15px] text-foreground">
            {points.map((p) => (
                <li key={p} className="flex gap-3">
                    <span aria-hidden="true" className="mt-[9px] size-1.5 shrink-0 rounded-full bg-foreground/40" />
                    <span>{p}</span>
                </li>
            ))}
        </ul>
    ) : null;
    if (wide) {
        return (
            <div className="flex flex-col gap-12">
                <div className="grid gap-8 lg:grid-cols-[minmax(0,6fr)_minmax(0,5fr)] lg:items-end lg:gap-16">
                    <SectionHeading id={id} label={label} title={title}>
                        {children}
                    </SectionHeading>
                    <div className="flex flex-col gap-6">
                        {pointList}
                        {more ? <MoreLink href={more.href}>{more.text}</MoreLink> : null}
                    </div>
                </div>
                <div className="min-w-0">{visual}</div>
            </div>
        );
    }
    return (
        <div className="grid items-center gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
            <div className={cn('flex flex-col gap-6', reverse && 'lg:order-2')}>
                <SectionHeading id={id} label={label} title={title}>
                    {children}
                </SectionHeading>
                {pointList}
                {more ? <MoreLink href={more.href}>{more.text}</MoreLink> : null}
            </div>
            <div className={cn('min-w-0', reverse && 'lg:order-1')}>{visual}</div>
        </div>
    );
}
