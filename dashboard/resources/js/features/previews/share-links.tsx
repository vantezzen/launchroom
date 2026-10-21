import { router } from '@inertiajs/react';
import { useState } from 'react';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import shareLinks from '@/routes/projects/previews/share-links';

/**
 * Share links of one protected preview: anyone with the link can open it
 * until it expires or is revoked (docs/06 §5).
 */
export function ShareLinksDialog({
    project,
    preview,
    open,
    onOpenChange,
    canShare,
}: {
    project: string;
    preview: App.Data.Previews.PreviewData;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canShare: boolean;
}) {
    const [label, setLabel] = useState('');
    const [days, setDays] = useState(7);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const usable = preview.shareLinks.filter((link) => link.usable);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[560px]">
                <DialogTitle>Share PR #{preview.number}</DialogTitle>
                <DialogDescription>
                    People without a launchroom login can open this preview with
                    a link until it expires, you revoke it, or the pull request
                    closes.
                </DialogDescription>

                {usable.length > 0 && (
                    <ul className="flex flex-col gap-3">
                        {usable.map((link) => (
                            <li key={link.id} className="flex flex-col gap-1.5">
                                <div className="flex items-baseline justify-between gap-2">
                                    <span className="type-label">
                                        {link.label ?? 'Share link'}
                                    </span>
                                    <span className="type-small text-muted-foreground">
                                        expires{' '}
                                        <TimeAgo value={link.expiresAt} />
                                    </span>
                                </div>
                                {link.url && (
                                    <CopyValue
                                        value={link.url}
                                        label="share link"
                                    />
                                )}
                                {canShare && (
                                    <div className="flex justify-end">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.delete(
                                                    shareLinks.destroy([
                                                        project,
                                                        preview.id,
                                                        link.id,
                                                    ]).url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Revoke
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {canShare ? (
                    <form
                        className="flex flex-col gap-4 border-t pt-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setProcessing(true);
                            router.post(
                                shareLinks.store([project, preview.id]).url,
                                { label: label || null, days },
                                {
                                    preserveScroll: true,
                                    onError: (failed) => setErrors(failed),
                                    onSuccess: () => {
                                        setErrors({});
                                        setLabel('');
                                    },
                                    onFinish: () => setProcessing(false),
                                },
                            );
                        }}
                    >
                        <div className="grid gap-4 sm:grid-cols-[1fr_120px]">
                            <div className="grid gap-2">
                                <Label htmlFor="share-label">
                                    Label (optional)
                                </Label>
                                <Input
                                    id="share-label"
                                    value={label}
                                    maxLength={100}
                                    placeholder="Client review"
                                    onChange={(event) =>
                                        setLabel(event.target.value)
                                    }
                                />
                                <InputError message={errors.label} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="share-days">Valid (days)</Label>
                                <Input
                                    id="share-days"
                                    type="number"
                                    min={1}
                                    max={30}
                                    className="tabular-nums"
                                    value={days}
                                    onChange={(event) =>
                                        setDays(Number(event.target.value))
                                    }
                                />
                                <InputError message={errors.days} />
                            </div>
                        </div>
                        <InputError message={errors.preview} />
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                Create link
                            </Button>
                        </div>
                    </form>
                ) : (
                    <p className="type-small text-muted-foreground">
                        Creating share links needs the developer role or higher.
                    </p>
                )}
            </DialogContent>
        </Dialog>
    );
}
