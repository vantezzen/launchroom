import { FitAddon } from '@xterm/addon-fit';
import { Terminal as XTerm } from '@xterm/xterm';
import type { ITheme } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';
import { cn } from '@/lib/utils';

export type TerminalHandle = {
    write: (bytes: Uint8Array | string) => void;
    focus: () => void;
    size: () => { cols: number; rows: number };
};

function token(name: string, fallback: string): string {
    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(name)
        .trim();

    return value === '' ? fallback : value;
}

/** The terminal's colours come from the theme tokens: calm, no neon. */
function theme(): ITheme {
    const foreground = token('--foreground', '#171717');
    const muted = token('--muted-foreground', '#4d4d4d');

    return {
        background: token('--recessed', '#fafafa'),
        foreground,
        cursor: foreground,
        cursorAccent: token('--recessed', '#fafafa'),
        selectionBackground: token('--signal-soft', 'rgba(0, 112, 243, 0.15)'),
        black: foreground,
        brightBlack: token('--ansi-gray', muted),
        red: token('--ansi-red', '#c6282b'),
        brightRed: token('--ansi-red', '#c6282b'),
        green: token('--ansi-green', '#2a7e3b'),
        brightGreen: token('--ansi-green', '#2a7e3b'),
        yellow: token('--ansi-yellow', '#a35200'),
        brightYellow: token('--ansi-yellow', '#a35200'),
        blue: token('--ansi-blue', '#0068d6'),
        brightBlue: token('--ansi-blue', '#0068d6'),
        magenta: token('--ansi-magenta', '#7d26cd'),
        brightMagenta: token('--ansi-magenta', '#7d26cd'),
        cyan: token('--ansi-cyan', '#00727a'),
        brightCyan: token('--ansi-cyan', '#00727a'),
        white: muted,
        brightWhite: foreground,
    };
}

/**
 * xterm.js on the recessed surface (docs/08 §15): Geist Mono 13 px, fitted
 * to its box. Reports keystrokes and (debounced) size changes.
 */
export const Terminal = forwardRef<
    TerminalHandle,
    {
        onData: (text: string) => void;
        onResize: (cols: number, rows: number) => void;
        /** Called once with the fitted size after mounting. */
        onMounted?: (cols: number, rows: number) => void;
        disabled?: boolean;
        className?: string;
    }
>(function Terminal(
    { onData, onResize, onMounted, disabled = false, className },
    ref,
) {
    const box = useRef<HTMLDivElement>(null);
    const term = useRef<XTerm | null>(null);
    const callbacks = useRef({ onData, onResize, onMounted, disabled });
    callbacks.current = { onData, onResize, onMounted, disabled };

    useImperativeHandle(ref, () => ({
        write: (bytes) => term.current?.write(bytes),
        focus: () => term.current?.focus(),
        size: () => ({
            cols: term.current?.cols ?? 80,
            rows: term.current?.rows ?? 24,
        }),
    }));

    useEffect(() => {
        const element = box.current;

        if (!element) {
            return;
        }

        const terminal = new XTerm({
            fontFamily: token('--font-mono', 'ui-monospace, monospace'),
            fontSize: 13,
            lineHeight: 1.25,
            cursorBlink: true,
            scrollback: 5000,
            allowProposedApi: false,
            theme: theme(),
        });
        const fit = new FitAddon();
        terminal.loadAddon(fit);
        terminal.open(element);
        term.current = terminal;

        try {
            fit.fit();
        } catch {
            // Not laid out yet.
        }

        callbacks.current.onMounted?.(terminal.cols, terminal.rows);
        const data = terminal.onData((text) => {
            if (!callbacks.current.disabled) {
                callbacks.current.onData(text);
            }
        });

        let timer: number | null = null;
        let last = `${terminal.cols}x${terminal.rows}`;
        const observer = new ResizeObserver(() => {
            if (timer !== null) {
                window.clearTimeout(timer);
            }

            timer = window.setTimeout(() => {
                try {
                    fit.fit();
                } catch {
                    return;
                }

                const size = `${terminal.cols}x${terminal.rows}`;

                // Only real changes: a resize makes some shells redraw their prompt.
                if (size !== last) {
                    last = size;
                    callbacks.current.onResize(terminal.cols, terminal.rows);
                }
            }, 120);
        });
        observer.observe(element);

        const scheme = window.matchMedia('(prefers-color-scheme: dark)');
        const retheme = () => {
            terminal.options.theme = theme();
        };
        const classes = new MutationObserver(retheme);
        classes.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
        scheme.addEventListener('change', retheme);

        return () => {
            if (timer !== null) {
                window.clearTimeout(timer);
            }

            observer.disconnect();
            classes.disconnect();
            scheme.removeEventListener('change', retheme);
            data.dispose();
            terminal.dispose();
            term.current = null;
        };
    }, []);

    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-xl border bg-recessed p-3 transition-opacity duration-base',
                disabled && 'opacity-60',
                className,
            )}
        >
            <div ref={box} className="size-full" aria-label="Terminal" />
        </div>
    );
});
