'use client';
import { Play } from 'lucide-react';
import Image from 'next/image';
import { useState } from 'react';
import { cn } from '@/lib/cn';

/**
 * A YouTube video that loads only on click. Until then the page shows a
 * self-hosted poster and contacts no Google server; the player then comes from
 * youtube-nocookie.com. Used on the landing page and in the docs (MDX).
 */
export function YouTubeVideo({
    id,
    title,
    poster,
    length,
    className,
}: {
    id: string;
    title: string;
    poster: string;
    length?: string;
    className?: string;
}) {
    const [playing, setPlaying] = useState(false);

    return (
        <div
            className={cn(
                'not-prose relative aspect-video overflow-hidden rounded-sheet border border-border bg-recessed shadow-card',
                className,
            )}
        >
            {playing ? (
                <iframe
                    className="absolute inset-0 size-full"
                    src={`https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0`}
                    title={title}
                    allow="autoplay; encrypted-media; fullscreen; picture-in-picture"
                    referrerPolicy="strict-origin-when-cross-origin"
                    allowFullScreen
                />
            ) : (
                <button
                    type="button"
                    onClick={() => setPlaying(true)}
                    className="group absolute inset-0 size-full cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-[-4px] focus-visible:outline-foreground"
                    aria-label={`Play video: ${title}`}
                >
                    <Image
                        src={poster}
                        alt=""
                        fill
                        sizes="(min-width: 1200px) 1100px, 100vw"
                        className="object-cover"
                    />
                    <span className="absolute inset-0 bg-black/20 transition-colors duration-150 group-hover:bg-black/30" />
                    <span className="absolute top-1/2 left-1/2 flex size-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white text-black shadow-float transition-transform duration-150 group-hover:scale-105 sm:size-20">
                        <Play className="ml-1 size-7 fill-current sm:size-8" aria-hidden="true" />
                    </span>
                    {length && (
                        <span className="absolute right-3 bottom-3 rounded-md bg-black/75 px-2 py-0.5 font-mono text-[12px] text-white">
                            {length}
                        </span>
                    )}
                </button>
            )}
        </div>
    );
}
