import { Head } from '@inertiajs/react';
import { Check } from 'lucide-react';
import type { Appearance as Mode } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as teamSettings } from '@/routes/team';

const themes: { value: Mode; label: string; description: string }[] = [
    { value: 'light', label: 'Light', description: 'Always light' },
    { value: 'dark', label: 'Dark', description: 'Always dark' },
    { value: 'system', label: 'System', description: 'Follows your device' },
];

/** A miniature of the app in a theme: sidebar, title, two cards. */
function Preview({ theme }: { theme: 'light' | 'dark' }) {
    const dark = theme === 'dark';

    return (
        <div
            className={cn(
                'flex h-full w-full gap-1.5 p-2',
                dark ? 'bg-[#000000]' : 'bg-[#fafafa]',
            )}
        >
            <div className="flex w-1/4 flex-col gap-1 pt-1">
                {[0, 1, 2].map((item) => (
                    <span
                        key={item}
                        className={cn(
                            'h-1.5 rounded-full',
                            dark ? 'bg-[#2e2e2e]' : 'bg-[#e0e0e0]',
                            item === 0 && 'w-3/4',
                        )}
                    />
                ))}
            </div>
            <div
                className={cn(
                    'flex flex-1 flex-col gap-1.5 rounded-md border p-2',
                    dark
                        ? 'border-[#1f1f1f] bg-[#0a0a0a]'
                        : 'border-[#ebebeb] bg-white',
                )}
            >
                <span
                    className={cn(
                        'h-2 w-1/2 rounded-full',
                        dark ? 'bg-[#ededed]' : 'bg-[#171717]',
                    )}
                />
                <div className="grid flex-1 grid-cols-2 gap-1.5">
                    {[0, 1].map((card) => (
                        <span
                            key={card}
                            className={cn(
                                'rounded border',
                                dark
                                    ? 'border-[#1f1f1f] bg-[#111111]'
                                    : 'border-[#ebebeb] bg-white',
                            )}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}

/**
 * Settings → Appearance (direction §9.14): three theme tiles with a small
 * preview each. Saved in this browser.
 */
export default function Appearance() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <>
            <Head title="Appearance" />

            <section className="flex flex-col gap-3.5">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h2 className="type-heading">Theme</h2>
                    <p className="type-body text-muted-foreground">
                        Saved in this browser.
                    </p>
                </div>
                <div
                    role="radiogroup"
                    aria-label="Theme"
                    className="grid grid-cols-3 gap-3"
                >
                    {themes.map((theme) => {
                        const selected = appearance === theme.value;

                        return (
                            <label
                                key={theme.value}
                                className={cn(
                                    'group relative flex cursor-pointer flex-col overflow-hidden rounded-xl border bg-card transition-[border-color,box-shadow] duration-fast',
                                    'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-background',
                                    selected
                                        ? 'border-foreground shadow-[0_0_0_0.5px_var(--foreground)]'
                                        : 'hover:border-border-strong',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="appearance"
                                    value={theme.value}
                                    checked={selected}
                                    onChange={() =>
                                        updateAppearance(theme.value)
                                    }
                                    className="sr-only"
                                />
                                <div className="aspect-[16/10] border-b">
                                    {theme.value === 'system' ? (
                                        <div className="flex h-full">
                                            <div className="w-1/2 overflow-hidden">
                                                <div className="h-full w-[200%]">
                                                    <Preview theme="light" />
                                                </div>
                                            </div>
                                            <div className="w-1/2 overflow-hidden">
                                                <div className="h-full w-[200%] -translate-x-1/2">
                                                    <Preview theme="dark" />
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        <Preview
                                            theme={
                                                theme.value === 'dark'
                                                    ? 'dark'
                                                    : 'light'
                                            }
                                        />
                                    )}
                                </div>
                                <div className="flex items-center justify-between gap-2 px-3.5 py-3">
                                    <span className="flex min-w-0 flex-col">
                                        <span className="type-label">
                                            {theme.label}
                                        </span>
                                        <span className="hidden type-small text-muted-foreground sm:block">
                                            {theme.description}
                                        </span>
                                    </span>
                                    {selected && (
                                        <Check
                                            aria-hidden="true"
                                            className="size-4 shrink-0"
                                        />
                                    )}
                                </div>
                            </label>
                        );
                    })}
                </div>
            </section>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        { title: 'Settings', href: teamSettings() },
        {
            title: 'Appearance',
            href: editAppearance(),
        },
    ],
};
