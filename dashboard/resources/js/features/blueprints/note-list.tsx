import { Check, Minus, TriangleAlert, X } from 'lucide-react';
import { cn } from '@/lib/utils';

type Note = App.Data.Blueprints.BlueprintNote;

const look: Record<
    App.Enums.BlueprintNoteKind,
    { icon: typeof Check; className: string; label: string }
> = {
    refused: { icon: X, className: 'text-status-error', label: 'Refused' },
    warning: {
        icon: TriangleAlert,
        className: 'text-status-warning',
        label: 'Check this',
    },
    translated: {
        icon: Check,
        className: 'text-muted-foreground',
        label: 'Translated',
    },
    ignored: {
        icon: Minus,
        className: 'text-muted-foreground',
        label: 'Ignored',
    },
};

/**
 * What became of each line (docs/17 §2.4): ✓ translated and – ignored are
 * quiet, ✕ refused is the only red, ⚠ asks to check something. Each row
 * names its line in the file.
 */
export function NoteList({
    notes,
    className,
}: {
    notes: Note[];
    className?: string;
}) {
    if (notes.length === 0) {
        return null;
    }

    return (
        <ul className={cn('flex flex-col gap-2', className)}>
            {notes.map((note, index) => {
                const { icon: Icon, className: tone, label } = look[note.kind];

                return (
                    <li
                        key={`${note.field}-${index}`}
                        className="flex items-start gap-2.5"
                    >
                        <Icon
                            aria-label={label}
                            className={cn('mt-0.5 size-3.5 shrink-0', tone)}
                        />
                        <span
                            className={cn(
                                'min-w-0 flex-1 type-small',
                                note.kind === 'ignored'
                                    ? 'text-muted-foreground'
                                    : 'text-foreground',
                            )}
                        >
                            {note.message}
                        </span>
                        {note.line !== null && (
                            <span className="shrink-0 type-mono-small text-muted-foreground tabular-nums">
                                line {note.line}
                            </span>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
