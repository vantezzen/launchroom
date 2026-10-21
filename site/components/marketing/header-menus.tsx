'use client';
import Image from 'next/image';
import Link from 'next/link';
import { ArrowRight, ChevronDown, Menu, X } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';
import { FeatureIcon } from '@/components/marketing/feature-icon';
import { GitHubMark } from '@/components/marketing/github-mark';
import { featurePages } from '@/lib/features';
import { cn } from '@/lib/cn';
import { githubUrl } from '@/lib/shared';

function FeatureLink({ feature, onNavigate }: { feature: (typeof featurePages)[number]; onNavigate?: () => void }) {
    return (
        <Link
            href={feature.href}
            onClick={onNavigate}
            className="group flex items-start gap-3 rounded-[10px] p-3 transition-colors duration-150 hover:bg-hover focus-visible:bg-hover"
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] border border-border bg-card text-foreground">
                <FeatureIcon name={feature.icon} className="size-[18px]" />
            </span>
            <span className="flex min-w-0 flex-col gap-0.5">
                <span className="text-sm font-medium text-foreground">{feature.title}</span>
                <span className="text-[13px] leading-5 text-muted-foreground">{feature.summary}</span>
            </span>
        </Link>
    );
}

/** Desktop "Features" menu: opens on click, hover or ArrowDown; Escape, outside click or leaving focus closes it. */
export function FeaturesMenu() {
    const [open, setOpen] = useState(false);
    const wrapper = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const timer = useRef<number | undefined>(undefined);
    const panelId = useId();

    const close = useCallback((focusTrigger = false) => {
        setOpen(false);
        if (focusTrigger) trigger.current?.focus();
    }, []);

    useEffect(() => {
        if (!open) return;
        const onDown = (e: PointerEvent) => {
            if (!wrapper.current?.contains(e.target as Node)) close();
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') close(true);
        };
        document.addEventListener('pointerdown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open, close]);

    const hover = (next: boolean) => (e: ReactPointerEvent) => {
        if (e.pointerType !== 'mouse') return;
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setOpen(next), next ? 60 : 160);
    };

    return (
        <div
            ref={wrapper}
            className="relative"
            onPointerEnter={hover(true)}
            onPointerLeave={hover(false)}
            onBlur={(e) => {
                if (!wrapper.current?.contains(e.relatedTarget as Node | null)) close();
            }}
        >
            <button
                ref={trigger}
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((v) => !v)}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setOpen(true);
                        window.requestAnimationFrame(() =>
                            wrapper.current?.querySelector<HTMLAnchorElement>(`#${CSS.escape(panelId)} a`)?.focus(),
                        );
                    }
                }}
                className={cn(
                    'inline-flex h-9 items-center gap-1 rounded-md px-3 text-sm text-muted-foreground transition-colors duration-150 hover:text-foreground',
                    open && 'text-foreground',
                )}
            >
                Features
                <ChevronDown
                    className={cn('size-3.5 transition-transform duration-150', open && 'rotate-180')}
                    aria-hidden="true"
                />
            </button>
            <div
                id={panelId}
                hidden={!open}
                className="lr-pop absolute top-full left-0 z-50 pt-2"
                onKeyDown={(e) => {
                    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
                    const links = Array.from(e.currentTarget.querySelectorAll<HTMLAnchorElement>('a'));
                    const i = links.indexOf(document.activeElement as HTMLAnchorElement);
                    const next = e.key === 'ArrowDown' ? i + 1 : i - 1;
                    links[(next + links.length) % links.length]?.focus();
                    e.preventDefault();
                }}
            >
                <div className="grid w-[680px] grid-cols-[minmax(0,1fr)_220px] gap-2 rounded-sheet border border-border bg-card p-2 shadow-float">
                    <ul className="flex flex-col">
                        {featurePages.map((f) => (
                            <li key={f.href}>
                                <FeatureLink feature={f} onNavigate={() => setOpen(false)} />
                            </li>
                        ))}
                    </ul>
                    <Link
                        href="/features/servers"
                        onClick={() => setOpen(false)}
                        className="group flex flex-col overflow-hidden rounded-[12px] border border-border bg-recessed transition-colors duration-150 hover:border-border-strong"
                    >
                        <span className="flex h-[140px] items-end justify-center gap-1 px-3 pb-3">
                            <Image
                                src="/devices/server.webp"
                                width={320}
                                height={200}
                                alt=""
                                sizes="140px"
                                className="h-[84px] w-auto object-contain"
                            />
                            <Image
                                src="/devices/tower.webp"
                                width={320}
                                height={200}
                                alt=""
                                sizes="140px"
                                className="h-[84px] w-auto object-contain"
                            />
                        </span>
                        <span className="flex flex-col gap-1 p-4">
                            <span className="text-sm font-medium text-foreground">
                                Your home machine as a build server
                            </span>
                            <span className="text-[13px] leading-5 text-muted-foreground">
                                Add it next to your VPS with one line. No ports to open.
                            </span>
                            <span className="mt-1 inline-flex items-center gap-1 text-[13px] font-medium text-foreground">
                                See how
                                <ArrowRight
                                    className="size-3.5 transition-transform duration-150 group-hover:translate-x-0.5"
                                    aria-hidden="true"
                                />
                            </span>
                        </span>
                    </Link>
                </div>
            </div>
        </div>
    );
}

/** Phone and tablet menu: a full-height panel under the header. */
export function MobileMenu() {
    const [open, setOpen] = useState(false);
    const panel = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const panelId = useId();

    useEffect(() => {
        if (!open) return;
        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        panel.current?.querySelector<HTMLAnchorElement>('a')?.focus();
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            }
        };
        document.addEventListener('keydown', onKey);
        return () => {
            document.body.style.overflow = previous;
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const done = () => setOpen(false);

    return (
        <div className="md:hidden">
            <button
                ref={trigger}
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                aria-label={open ? 'Close menu' : 'Open menu'}
                onClick={() => setOpen((v) => !v)}
                className="inline-flex size-11 items-center justify-center rounded-md text-foreground hover:bg-hover"
            >
                {open ? <X className="size-5" aria-hidden="true" /> : <Menu className="size-5" aria-hidden="true" />}
            </button>
            <div
                ref={panel}
                id={panelId}
                hidden={!open}
                className="lr-pop fixed inset-x-0 top-16 bottom-0 z-50 overflow-y-auto border-t border-border bg-background"
            >
                <nav aria-label="Mobile" className="flex flex-col gap-6 px-4 py-5">
                    <div className="flex flex-col gap-1">
                        <p className="px-3 type-label text-muted-foreground">Features</p>
                        <ul className="flex flex-col">
                            {featurePages.map((f) => (
                                <li key={f.href}>
                                    <FeatureLink feature={f} onNavigate={done} />
                                </li>
                            ))}
                        </ul>
                    </div>
                    <ul className="flex flex-col border-t border-border pt-4">
                        {[
                            { href: '/docs/how-it-works/architecture', label: 'How it works' },
                            { href: '/docs', label: 'Docs' },
                        ].map((l) => (
                            <li key={l.href}>
                                <Link
                                    href={l.href}
                                    onClick={done}
                                    className="flex h-12 items-center rounded-md px-3 text-[15px] text-foreground hover:bg-hover"
                                >
                                    {l.label}
                                </Link>
                            </li>
                        ))}
                        <li>
                            <a
                                href={githubUrl}
                                className="flex h-12 items-center gap-2 rounded-md px-3 text-[15px] text-foreground hover:bg-hover"
                            >
                                <GitHubMark className="size-4" />
                                GitHub
                            </a>
                        </li>
                    </ul>
                    <Link
                        href="/docs/getting-started/install"
                        onClick={done}
                        className="flex h-12 items-center justify-center rounded-control bg-primary text-[15px] font-medium text-primary-foreground"
                    >
                        Install launchroom
                    </Link>
                </nav>
            </div>
        </div>
    );
}
