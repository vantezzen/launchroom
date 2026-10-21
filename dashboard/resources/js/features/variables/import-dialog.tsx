import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { importMethod } from '@/routes/variables';
import { preview as previewRoute } from '@/routes/variables/import';
import { api } from './api';

type Preview = App.Data.Variables.ImportPreviewData;
type Entry = App.Data.Variables.ImportPreviewEntryData;

const statusText: Record<string, string> = {
    new: 'New',
    update: 'Replaces value',
    conflict: 'Also used elsewhere',
};

/**
 * Paste-to-import (docs/06 §3.4): the pasted `.env` is parsed on the server
 * (no values come back), each key shows what importing does, secret-looking
 * keys are pre-marked secret, keys shared with other environments are left
 * out (edit them in the table), and the user picks what to import.
 */
export function ImportDialog({
    open,
    onOpenChange,
    initialContent,
    urls,
    environmentName,
    onImported,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialContent: string;
    urls: { project: string; environment: string; service: string };
    environmentName: string;
    onImported: (message: string) => void;
}) {
    const [content, setContent] = useState(initialContent);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [skipped, setSkipped] = useState<Set<string>>(new Set());
    const [types, setTypes] = useState<Record<string, App.Enums.VariableType>>(
        {},
    );
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open || content.trim() === '') {
            setPreview(null);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            api<Preview>(
                'post',
                previewRoute(urls).url,
                { content },
                controller.signal,
            )
                .then((result) => {
                    setPreview(result);
                    setError(null);
                    setSkipped(
                        new Set(
                            result.entries
                                .filter((entry) => entry.status === 'conflict')
                                .map((entry) => entry.key),
                        ),
                    );
                    setTypes({});
                })
                .catch((failure: unknown) => {
                    if (!controller.signal.aborted) {
                        setError(
                            failure instanceof Error
                                ? failure.message
                                : 'Could not read the file.',
                        );
                    }
                });
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [open, content, urls]);

    const selected = useMemo(
        () =>
            (preview?.entries ?? []).filter((entry) => !skipped.has(entry.key)),
        [preview, skipped],
    );

    const typeOf = (entry: Entry): App.Enums.VariableType =>
        types[entry.key] ?? entry.suggestedType;

    const submit = async () => {
        setBusy(true);
        setError(null);

        try {
            const result = await api<App.Data.Variables.ImportResult>(
                'post',
                importMethod(urls).url,
                {
                    content,
                    only: selected.map((entry) => entry.key),
                    types: Object.fromEntries(
                        selected
                            .filter((entry) => entry.status === 'new')
                            .map((entry) => [entry.key, typeOf(entry)]),
                    ),
                },
            );
            onImported(
                `${result.created.length} added, ${result.updated.length} updated. Deploy to apply them.`,
            );
            onOpenChange(false);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'The import failed.',
            );
        } finally {
            setBusy(false);
        }
    };

    const syntaxErrors = preview?.errors ?? [];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogTitle>Import .env</DialogTitle>
                <DialogDescription>
                    Variables are added to {environmentName} and staged until
                    you deploy. Values are stored exactly as written, without
                    interpolation.
                </DialogDescription>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="import-content">File contents</Label>
                    <textarea
                        id="import-content"
                        value={content}
                        onChange={(event) => setContent(event.target.value)}
                        rows={6}
                        spellCheck={false}
                        placeholder={
                            'DATABASE_URL=postgres://…\nSTRIPE_SECRET_KEY=sk_live_…'
                        }
                        className="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 type-mono-small shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>

                {syntaxErrors.length > 0 && (
                    <ul
                        role="alert"
                        className="flex flex-col gap-1 type-small text-status-error"
                    >
                        {syntaxErrors.map((item) => (
                            <li key={`${item.line}-${item.message}`}>
                                Line {item.line}: {item.message}
                            </li>
                        ))}
                    </ul>
                )}

                {preview &&
                    syntaxErrors.length === 0 &&
                    preview.entries.length > 0 && (
                        <div className="max-h-72 overflow-y-auto">
                            <table className="w-full type-body">
                                <thead className="sticky top-0 bg-background">
                                    <tr className="border-b text-left type-label text-muted-foreground">
                                        <th className="w-8 py-2">
                                            <span className="sr-only">
                                                Import
                                            </span>
                                        </th>
                                        <th className="py-2 pr-3 font-medium">
                                            Key
                                        </th>
                                        <th className="py-2 pr-3 font-medium">
                                            Effect
                                        </th>
                                        <th className="w-36 py-2 font-medium">
                                            Type
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {preview.entries.map((entry) => (
                                        <tr key={entry.key}>
                                            <td className="py-1.5">
                                                <Checkbox
                                                    aria-label={`Import ${entry.key}`}
                                                    checked={
                                                        !skipped.has(entry.key)
                                                    }
                                                    disabled={
                                                        entry.status ===
                                                        'conflict'
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setSkipped(
                                                            (current) => {
                                                                const next =
                                                                    new Set(
                                                                        current,
                                                                    );

                                                                if (
                                                                    checked ===
                                                                    true
                                                                ) {
                                                                    next.delete(
                                                                        entry.key,
                                                                    );
                                                                } else {
                                                                    next.add(
                                                                        entry.key,
                                                                    );
                                                                }

                                                                return next;
                                                            },
                                                        )
                                                    }
                                                />
                                            </td>
                                            <td className="py-1.5 pr-3">
                                                <span className="type-mono-small">
                                                    {entry.key}
                                                </span>
                                                <span className="ml-2 type-small text-muted-foreground">
                                                    line {entry.line}
                                                </span>
                                            </td>
                                            <td className="py-1.5 pr-3">
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        entry.status ===
                                                        'conflict'
                                                            ? 'font-normal text-status-progress'
                                                            : 'font-normal'
                                                    }
                                                >
                                                    {statusText[entry.status] ??
                                                        entry.status}
                                                </Badge>
                                            </td>
                                            <td className="py-1.5">
                                                {entry.status === 'new' &&
                                                !entry.browserInlined ? (
                                                    <Select
                                                        value={typeOf(entry)}
                                                        onValueChange={(next) =>
                                                            setTypes(
                                                                (current) => ({
                                                                    ...current,
                                                                    [entry.key]:
                                                                        next as App.Enums.VariableType,
                                                                }),
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            className="h-7 w-32"
                                                            aria-label={`Type of ${entry.key}`}
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="config">
                                                                Config
                                                            </SelectItem>
                                                            <SelectItem value="secret">
                                                                Secret
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                ) : (
                                                    <span className="type-small text-muted-foreground">
                                                        {entry.existingSecret ||
                                                        typeOf(entry) ===
                                                            'secret'
                                                            ? 'Secret'
                                                            : 'Config'}
                                                        {entry.browserInlined &&
                                                            ' (public)'}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                {preview?.entries.some(
                    (entry) => entry.status === 'conflict',
                ) && (
                    <p className="type-small text-muted-foreground">
                        Keys marked “Also used elsewhere” are shared with other
                        environments. Edit them in the table to change or split
                        them.
                    </p>
                )}

                {(preview?.warnings ?? []).length > 0 && (
                    <ul className="flex flex-col gap-1 type-small text-muted-foreground">
                        {preview?.warnings.map((item) => (
                            <li key={`${item.line}-${item.message}`}>
                                Line {item.line}: {item.message}
                            </li>
                        ))}
                    </ul>
                )}

                {error && (
                    <p role="alert" className="type-body text-status-error">
                        {error}
                    </p>
                )}

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        disabled={
                            busy ||
                            selected.length === 0 ||
                            syntaxErrors.length > 0
                        }
                        onClick={() => void submit()}
                    >
                        Import {selected.length}{' '}
                        {selected.length === 1 ? 'variable' : 'variables'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
