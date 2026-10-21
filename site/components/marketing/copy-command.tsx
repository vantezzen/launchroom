'use client';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/cn';

/** A shell command with a copy button: the call to action of the site. */
export function CopyCommand({ command, className }: { command: string; className?: string }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        try {
            await navigator.clipboard.writeText(command);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1600);
        } catch {
            setCopied(false);
        }
    }

    return (
        <div
            className={cn(
                'flex h-11 min-w-0 items-center gap-3 text-left rounded-control border border-border-strong bg-card pr-1.5 pl-4 font-mono text-[13px] text-foreground shadow-card',
                className,
            )}
        >
            <span className="text-faint select-none" aria-hidden="true">
                $
            </span>
            <code
                tabIndex={0}
                aria-label={`Command: ${command}`}
                className="min-w-0 flex-1 overflow-x-auto rounded-sm whitespace-nowrap [scrollbar-width:none]"
            >
                {command}
            </code>
            <button
                type="button"
                onClick={copy}
                className="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors duration-150 hover:bg-hover hover:text-foreground"
                aria-label={copied ? 'Copied' : 'Copy command'}
            >
                {copied ? (
                    <Check className="size-4 text-status-ok" aria-hidden="true" />
                ) : (
                    <Copy className="size-4" aria-hidden="true" />
                )}
            </button>
            <span className="sr-only" aria-live="polite">
                {copied ? 'Copied to clipboard' : ''}
            </span>
        </div>
    );
}
