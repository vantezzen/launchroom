import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { vocabulary } from '@/lib/vocabulary';

const REASONS = Object.entries(vocabulary.Security.DismissReason).map(
    ([value, { label }]) => ({ value, label }),
);

/**
 * Why a finding doesn't apply and an optional note (docs/22 §5.5), shared
 * by "Not affected…" of one finding and of a whole fix group.
 */
export function DismissReasonFields({
    reason,
    onReasonChange,
    note,
    onNoteChange,
}: {
    reason: string;
    onReasonChange: (reason: string) => void;
    note: string;
    onNoteChange: (note: string) => void;
}) {
    return (
        <>
            <fieldset className="flex flex-col gap-2">
                <legend className="mb-2 type-label">Why</legend>
                {REASONS.map((option) => (
                    <label
                        key={option.value}
                        className={cn(
                            'flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 type-body',
                            reason === option.value
                                ? 'border-foreground'
                                : 'hover:border-border-strong',
                        )}
                    >
                        <input
                            type="radio"
                            name="reason"
                            value={option.value}
                            checked={reason === option.value}
                            onChange={() => onReasonChange(option.value)}
                            className="accent-foreground"
                        />
                        {option.label}
                    </label>
                ))}
            </fieldset>
            <label className="flex flex-col gap-2">
                <span className="type-label">Note (optional)</span>
                <Textarea
                    value={note}
                    maxLength={500}
                    rows={3}
                    onChange={(event) => onNoteChange(event.target.value)}
                />
            </label>
        </>
    );
}
