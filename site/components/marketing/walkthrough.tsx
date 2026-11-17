'use client';
import { Play, X } from 'lucide-react';
import Image from 'next/image';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import { tutorialVideo } from '@/lib/shared';

/**
 * The walkthrough video as the landing page's trust anchor: a link under the
 * hero, a small floating card once the hero is out of view, and one dialog
 * both open. The player loads from youtube-nocookie.com only when the dialog
 * opens, so a page view contacts no Google server.
 */
const OPEN_EVENT = 'launchroom:walkthrough';
const DISMISSED_KEY = 'launchroom.walkthrough-dock.dismissed';

function readDismissed(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }
    try {
        return window.localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
}

function openWalkthrough() {
    window.dispatchEvent(new Event(OPEN_EVENT));
}

export function WalkthroughButton({ className }: { className?: string }) {
    return (
        <button
            type="button"
            onClick={openWalkthrough}
            className={cn(
                'group inline-flex cursor-pointer items-center gap-3 rounded-[12px] border border-border bg-card p-1.5 pr-4 text-left shadow-card transition-colors duration-150 hover:border-border-strong',
                className,
            )}
        >
            <span className="relative block aspect-video w-[88px] shrink-0 overflow-hidden rounded-[8px] bg-recessed">
                <Image src={tutorialVideo.poster} alt="" fill sizes="88px" className="object-cover" />
                <span className="absolute inset-0 bg-black/15 transition-colors duration-150 group-hover:bg-black/25" />
                <span className="absolute top-1/2 left-1/2 flex size-7 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white text-black shadow-float transition-transform duration-150 group-hover:scale-105">
                    <Play className="ml-0.5 size-3.5 fill-current" aria-hidden="true" />
                </span>
            </span>
            <span className="flex flex-col">
                <span className="text-[14px] font-medium text-foreground">Watch the walkthrough</span>
                <span className="text-[13px] text-muted-foreground">
                    {tutorialVideo.length}, from the install to every page
                </span>
            </span>
        </button>
    );
}

/**
 * A small card at the bottom right with the video's poster (the presenter is
 * in frame). It shows after the hero, hides while the walkthrough section is
 * on screen, and stays closed once dismissed.
 */
export function WalkthroughDock() {
    const [past, setPast] = useState(false);
    const [sectionVisible, setSectionVisible] = useState(false);
    // Read once on the client; the server renders the card hidden either way.
    const [dismissed, setDismissed] = useState(readDismissed);

    useEffect(() => {
        const onScroll = () => setPast(window.scrollY > window.innerHeight * 0.9);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        const section = document.getElementById('walkthrough');
        const observer = section
            ? new IntersectionObserver(([entry]) => setSectionVisible(entry.isIntersecting), { threshold: 0.1 })
            : null;
        if (section && observer) {
            observer.observe(section);
        }
        return () => {
            window.removeEventListener('scroll', onScroll);
            observer?.disconnect();
        };
    }, []);

    const dismiss = () => {
        setDismissed(true);
        try {
            window.localStorage.setItem(DISMISSED_KEY, '1');
        } catch {
            // Private mode or blocked storage: the card stays closed for this visit.
        }
    };

    const shown = past && !sectionVisible && !dismissed;

    return (
        <div
            className={cn(
                'fixed right-4 bottom-4 left-4 z-40 transition-all duration-200 sm:right-6 sm:bottom-6 sm:left-auto sm:w-[300px]',
                shown ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-3 opacity-0',
            )}
            aria-hidden={!shown}
        >
            <div className="flex items-center overflow-hidden rounded-card border border-border bg-card shadow-float sm:block">
                <button
                    type="button"
                    onClick={openWalkthrough}
                    tabIndex={shown ? 0 : -1}
                    className="group relative block aspect-video w-28 shrink-0 cursor-pointer sm:w-full focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-foreground"
                    aria-label={`Play the ${tutorialVideo.length} walkthrough`}
                >
                    <Image
                        src={tutorialVideo.poster}
                        alt=""
                        fill
                        sizes="(min-width: 640px) 300px, 112px"
                        className="object-cover"
                    />
                    <span className="absolute inset-0 bg-black/15 transition-colors duration-150 group-hover:bg-black/25" />
                    <span className="absolute top-1/2 left-1/2 flex size-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white text-black shadow-float sm:size-11 transition-transform duration-150 group-hover:scale-105">
                        <Play className="ml-0.5 size-4 fill-current sm:size-5" aria-hidden="true" />
                    </span>
                    <span className="absolute right-2 bottom-2 hidden rounded bg-black/75 px-1.5 py-0.5 font-mono text-[11px] text-white sm:block">
                        {tutorialVideo.length}
                    </span>
                </button>
                <div className="flex min-w-0 flex-1 items-start gap-2 px-3.5 py-3">
                    <div className="min-w-0 flex-1">
                        <p className="text-[13px] font-medium text-foreground">The full walkthrough</p>
                        <p className="text-[12px] leading-[18px] text-muted-foreground">
                            From the install to every page of the dashboard.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={dismiss}
                        tabIndex={shown ? 0 : -1}
                        className="-mt-0.5 -mr-1 flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors duration-150 hover:bg-recessed hover:text-foreground"
                        aria-label="Close"
                    >
                        <X className="size-4" aria-hidden="true" />
                    </button>
                </div>
            </div>
        </div>
    );
}

/** The one player dialog; WalkthroughButton and WalkthroughDock open it. */
export function WalkthroughDialog() {
    const ref = useRef<HTMLDialogElement>(null);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const onOpen = () => {
            setOpen(true);
            ref.current?.showModal();
        };
        window.addEventListener(OPEN_EVENT, onOpen);
        return () => window.removeEventListener(OPEN_EVENT, onOpen);
    }, []);

    return (
        <dialog
            ref={ref}
            onClose={() => setOpen(false)}
            onClick={(event) => {
                if (event.target === ref.current) {
                    ref.current.close();
                }
            }}
            aria-label={tutorialVideo.title}
            className="m-auto w-[min(1100px,calc(100vw-32px))] overflow-visible bg-transparent p-0 backdrop:bg-black/70"
        >
            <div className="relative">
                <button
                    type="button"
                    onClick={() => ref.current?.close()}
                    className="absolute -top-11 right-0 flex size-9 cursor-pointer items-center justify-center rounded-full bg-white/10 text-white transition-colors duration-150 hover:bg-white/20"
                    aria-label="Close video"
                >
                    <X className="size-5" aria-hidden="true" />
                </button>
                <div className="aspect-video overflow-hidden rounded-sheet bg-black shadow-float">
                    {open && (
                        <iframe
                            className="size-full"
                            src={`https://www.youtube-nocookie.com/embed/${tutorialVideo.id}?autoplay=1&rel=0`}
                            title={tutorialVideo.title}
                            allow="autoplay; encrypted-media; fullscreen; picture-in-picture"
                            referrerPolicy="strict-origin-when-cross-origin"
                            allowFullScreen
                        />
                    )}
                </div>
            </div>
        </dialog>
    );
}
