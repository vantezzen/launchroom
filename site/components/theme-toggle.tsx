'use client';
import { Monitor, Moon, Sun } from 'lucide-react';
import { useTheme } from 'next-themes';
import { useEffect, useState } from 'react';
import { cn } from '@/lib/cn';

const options = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'system', label: 'System', icon: Monitor },
    { value: 'dark', label: 'Dark', icon: Moon },
] as const;

/** Segmented Light / System / Dark switch, like the dashboard's appearance tabs. */
export function ThemeToggle({ className }: { className?: string }) {
    const { theme, setTheme } = useTheme();
    const [mounted, setMounted] = useState(false);
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the theme is only known on the client
    useEffect(() => setMounted(true), []);

    return (
        <div
            role="radiogroup"
            aria-label="Theme"
            className={cn('inline-flex rounded-full border border-border p-0.5', className)}
        >
            {options.map(({ value, label, icon: Icon }) => {
                const active = mounted && theme === value;
                return (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        aria-label={label}
                        title={label}
                        onClick={() => setTheme(value)}
                        className={cn(
                            'inline-flex size-7 items-center justify-center rounded-full text-muted-foreground transition-colors duration-150 hover:text-foreground',
                            active && 'bg-hover text-foreground',
                        )}
                    >
                        <Icon className="size-3.5" aria-hidden="true" />
                    </button>
                );
            })}
        </div>
    );
}
