import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/logo';
import { cn } from '@/lib/utils';

type Template = App.Data.Templates.TemplateSummaryData;

const CARD =
    'group relative flex overflow-hidden rounded-[14px] border bg-card text-left shadow-card transition-[border-color,box-shadow] duration-fast hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none';

/** The canvas' dot grid: the one functional pattern (design-system §1.9). */
const DOTS =
    'bg-[radial-gradient(var(--border-strong)_1px,transparent_1.2px)] [background-size:12px_12px]';

const STAGE =
    'flex w-[116px] shrink-0 items-center justify-center border-r bg-recessed sm:w-[124px]';

/**
 * "Start with a blank canvas": the empty canvas in miniature (three group
 * frames waiting for services) beside the name and one line.
 */
export function BlankCanvasCard({ onStart }: { onStart: () => void }) {
    return (
        <button type="button" onClick={onStart} className={CARD}>
            <span aria-hidden="true" className={cn(STAGE, 'gap-1.5', DOTS)}>
                {[0, 1, 2].map((frame) => (
                    <span
                        key={frame}
                        className="flex h-[54px] w-[26px] items-center justify-center rounded-[8px] border border-dashed border-border-strong bg-card/80"
                    >
                        <Plus className="size-3 text-faint transition-colors duration-fast group-hover:text-foreground" />
                    </span>
                ))}
            </span>
            <StartText
                title="Start with a blank canvas"
                line="Add services, databases and images yourself."
            />
        </button>
    );
}

/**
 * "Import a Compose file": the start of a compose.yml beside the name.
 */
export function ComposeCard({ href }: { href: string }) {
    return (
        <Link href={href} className={CARD}>
            <span aria-hidden="true" className={STAGE}>
                <span className="flex flex-col rounded-[8px] border bg-card px-2.5 py-2 font-mono text-[10px] leading-[15px] text-muted-foreground shadow-card">
                    <span>
                        <span className="text-foreground">services</span>:
                    </span>
                    <span className="pl-2.5">
                        <span className="text-foreground">web</span>: …
                    </span>
                    <span className="pl-2.5">
                        <span className="text-foreground">db</span>: …
                    </span>
                </span>
            </span>
            <StartText
                title="Import a Compose file"
                line="Each service in your compose.yml joins the canvas."
            />
        </Link>
    );
}

function StartText({ title, line }: { title: string; line: string }) {
    return (
        <span className="flex min-w-0 flex-col justify-center gap-0.5 px-4 py-4">
            <span className="type-label text-[14px] text-foreground">
                {title}
            </span>
            <span className="type-small text-muted-foreground">{line}</span>
        </span>
    );
}

/**
 * A template as a small picture of what it runs: its own mark, then the
 * services it brings (database, worker…) joined by a hairline, the way they
 * sit on the canvas.
 */
export function TemplateTile({
    template,
    category,
    onOpen,
}: {
    template: Template;
    category?: string;
    onOpen: (template: Template) => void;
}) {
    const others = [
        ...new Set(
            template.preview.services
                .map((service) => service.logo)
                .filter((logo) => logo !== template.logo),
        ),
    ].slice(0, 2);

    return (
        <button
            type="button"
            onClick={() => onOpen(template)}
            className={cn(CARD, 'h-full w-full flex-col')}
        >
            <span
                aria-hidden="true"
                className="flex h-[76px] items-center justify-center border-b bg-recessed sm:h-[96px]"
            >
                <Logo
                    kind={template.logo}
                    name={template.name}
                    size={44}
                    decorative
                    className="shadow-card"
                />
                {others.map((logo) => (
                    <Connected key={logo}>
                        <Logo kind={logo} size={32} decorative />
                    </Connected>
                ))}
            </span>
            <span className="flex min-w-0 flex-1 flex-col gap-0.5 px-4 py-3.5">
                <span className="flex min-w-0 items-baseline justify-between gap-2">
                    <span className="truncate type-label text-foreground">
                        {template.name}
                    </span>
                    {category && (
                        <span className="hidden shrink-0 type-small text-faint sm:inline">
                            {category}
                        </span>
                    )}
                </span>
                <span className="line-clamp-1 type-small text-muted-foreground">
                    {template.tagline}
                </span>
            </span>
        </button>
    );
}

function Connected({ children }: { children: ReactNode }) {
    return (
        <>
            <span className="h-px w-5 bg-border-strong" />
            {children}
        </>
    );
}
