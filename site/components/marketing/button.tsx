import Link from 'next/link';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/cn';

const styles = {
    primary: 'bg-primary text-primary-foreground hover:opacity-90',
    secondary: 'border border-border-strong bg-background text-foreground hover:bg-hover',
    ghost: 'text-muted-foreground hover:text-foreground hover:bg-hover',
} as const;

export function ButtonLink({
    href,
    variant = 'primary',
    size = 'md',
    className,
    children,
    ...rest
}: {
    href: string;
    variant?: keyof typeof styles;
    size?: 'sm' | 'md';
    className?: string;
    children: ReactNode;
} & Omit<ComponentProps<'a'>, 'href'>) {
    const external = /^https?:\/\//.test(href);
    const classes = cn(
        'inline-flex items-center justify-center gap-1.5 rounded-control font-medium whitespace-nowrap transition-[background-color,opacity,color] duration-150',
        size === 'sm' ? 'h-8 px-3 text-[13px]' : 'h-10 px-4 text-sm',
        styles[variant],
        className,
    );
    if (external) {
        return (
            <a href={href} className={classes} {...rest}>
                {children}
            </a>
        );
    }
    return (
        <Link href={href} className={classes} {...rest}>
            {children}
        </Link>
    );
}
