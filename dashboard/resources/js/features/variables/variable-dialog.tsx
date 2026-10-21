import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store, update } from '@/routes/variables';
import { api } from './api';
import { looksLikeEnv } from './env-paste';
import { ReferenceInput } from './reference-input';

type Row = App.Data.Variables.VariableRowData;
type Data = App.Data.Variables.ServiceVariablesData;

const KEY_PATTERN = /^[A-Za-z_][A-Za-z0-9_]{0,255}$/;

type Target = {
    environmentId: string | null;
    allPreviews: boolean;
    branchPattern: string | null;
};

/**
 * Add or edit a variable (docs/06 §3.1, §3.4). Every edit is staged. Secret
 * values are write-only: editing a secret replaces its value only when a new
 * one is entered.
 */
export function VariableDialog({
    open,
    onOpenChange,
    data,
    variable,
    urls,
    onSaved,
    onPasteEnv,
    preset,
}: {
    /** New variable prefilled from a link (`?add=NAME&value=`). */
    preset?: { key: string; value: string };
    open: boolean;
    onOpenChange: (open: boolean) => void;
    data: Data;
    variable: Row | null;
    urls: { project: string; environment: string; service: string };
    onSaved: (message: string) => void;
    onPasteEnv: (text: string) => void;
}) {
    const editing = variable !== null;
    const isSecret = variable?.type === 'secret';
    const [key, setKey] = useState(variable?.key ?? preset?.key ?? '');
    const [value, setValue] = useState(variable?.value ?? preset?.value ?? '');
    const [type, setType] = useState<App.Enums.VariableType>(
        variable?.type ?? 'config',
    );
    const [exposure, setExposure] = useState<string>(
        variable?.exposureOverridden ? variable.exposure : 'auto',
    );
    const [targets, setTargets] = useState<Target[]>(
        variable?.targets.length
            ? variable.targets.map((target) => ({
                  environmentId: target.environmentId,
                  allPreviews: target.allPreviews,
                  branchPattern: target.branchPattern,
              }))
            : [
                  {
                      environmentId: data.environmentId,
                      allPreviews: false,
                      branchPattern: null,
                  },
              ],
    );
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    // A row shared by several environments: edit here only (split) or all.
    const shared =
        editing &&
        variable.targets.length > 1 &&
        variable.targets.some(
            (target) => target.environmentId === data.environmentId,
        );
    const [scope, setScope] = useState<'only' | 'all'>('only');
    const here =
        data.environments.find(
            (environment) => environment.id === data.environmentId,
        )?.name ?? 'this environment';

    // The server classifies keys (docs/06 §3.3); known for saved variables.
    const inlined =
        variable !== null && variable.browserInlined && key === variable.key;
    const keyInvalid = key !== '' && !KEY_PATTERN.test(key);
    const previews = targets.find((target) => target.allPreviews);

    const toggleEnvironment = (id: string, checked: boolean) =>
        setTargets((current) =>
            checked
                ? [
                      ...current,
                      {
                          environmentId: id,
                          allPreviews: false,
                          branchPattern: null,
                      },
                  ]
                : current.filter((target) => target.environmentId !== id),
        );

    const togglePreviews = (checked: boolean) =>
        setTargets((current) =>
            checked
                ? [
                      ...current,
                      {
                          environmentId: null,
                          allPreviews: true,
                          branchPattern: null,
                      },
                  ]
                : current.filter((target) => !target.allPreviews),
        );

    const submit = async () => {
        setBusy(true);
        setError(null);

        const payload: Record<string, unknown> =
            shared && scope === 'only'
                ? {
                      key,
                      type: inlined ? 'config' : type,
                      exposureOverride: exposure === 'auto' ? null : exposure,
                      onlyEnvironment: data.environmentId,
                  }
                : {
                      key,
                      type: inlined ? 'config' : type,
                      targets,
                      exposureOverride: exposure === 'auto' ? null : exposure,
                  };

        if (!editing || !isSecret || value !== '') {
            payload.value = value;
        }

        try {
            if (editing) {
                await api(
                    'patch',
                    update({
                        project: urls.project,
                        environment: urls.environment,
                        variable: variable.id,
                    }).url,
                    payload,
                );
            } else {
                await api('post', store(urls).url, payload);
            }

            onSaved(
                editing
                    ? `${key} updated. Deploy to apply it.`
                    : `${key} added. Deploy to apply it.`,
            );
            onOpenChange(false);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Could not save the variable.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <DialogTitle>
                    {editing ? `Edit ${variable.key}` : 'Add variable'}
                </DialogTitle>
                <DialogDescription>
                    Staged until you deploy. Reference other values with{' '}
                    <code className="type-mono-small">
                        {'${{service.KEY}}'}
                    </code>
                    .
                </DialogDescription>
                <form
                    className="flex flex-col gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="variable-key">Key</Label>
                        <Input
                            id="variable-key"
                            value={key}
                            autoFocus={!editing}
                            autoComplete="off"
                            spellCheck={false}
                            className="type-mono"
                            aria-invalid={keyInvalid || undefined}
                            aria-describedby="variable-key-help"
                            onChange={(event) =>
                                setKey(event.target.value.trim())
                            }
                            onPaste={(event) => {
                                const text =
                                    event.clipboardData.getData('text');

                                if (
                                    !editing &&
                                    text.includes('\n') &&
                                    looksLikeEnv(text)
                                ) {
                                    event.preventDefault();
                                    onOpenChange(false);
                                    onPasteEnv(text);
                                }
                            }}
                        />
                        <p
                            id="variable-key-help"
                            className={
                                keyInvalid
                                    ? 'type-small text-status-error'
                                    : 'type-small text-muted-foreground'
                            }
                        >
                            {keyInvalid
                                ? 'Use letters, digits and underscores, not starting with a digit.'
                                : 'Paste a whole .env file here to import it.'}
                        </p>
                    </div>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="variable-value">Value</Label>
                        <ReferenceInput
                            id="variable-value"
                            value={value}
                            onChange={setValue}
                            scopes={data.scopes}
                            placeholder={
                                isSecret
                                    ? 'Leave empty to keep the current value'
                                    : 'Value, ${{service.KEY}} or ${{ secret(32) }}'
                            }
                            describedBy="variable-value-help"
                        />
                        <p
                            id="variable-value-help"
                            className="type-small text-muted-foreground"
                        >
                            Stored as is, including spaces and new lines.{' '}
                            <code className="type-mono-small">
                                {'${{ secret(32) }}'}
                            </code>{' '}
                            generates a value once.
                        </p>
                    </div>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="variable-type">Type</Label>
                            <Select
                                value={inlined ? 'config' : type}
                                onValueChange={(next) =>
                                    setType(next as App.Enums.VariableType)
                                }
                                disabled={inlined}
                            >
                                <SelectTrigger id="variable-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="config">
                                        Config: readable by members
                                    </SelectItem>
                                    <SelectItem value="secret">
                                        Secret: write-only
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {inlined && (
                                <p className="type-small text-muted-foreground">
                                    {key} is baked into the browser bundle at
                                    build time and visible to anyone, so it
                                    can’t be a secret.
                                </p>
                            )}
                        </div>

                        {shared && (
                            <fieldset className="flex flex-col gap-2 sm:col-span-2">
                                <legend className="mb-2 type-label">
                                    Apply this edit to
                                </legend>
                                <label className="flex items-start gap-2 type-body">
                                    <input
                                        type="radio"
                                        name="variable-scope"
                                        className="mt-1 accent-foreground"
                                        checked={scope === 'only'}
                                        onChange={() => setScope('only')}
                                    />
                                    <span className="flex flex-col">
                                        <span>{here} only</span>
                                        <span className="type-small text-muted-foreground">
                                            {here} gets its own row; the other
                                            environments keep this one.
                                        </span>
                                    </span>
                                </label>
                                <label className="flex items-start gap-2 type-body">
                                    <input
                                        type="radio"
                                        name="variable-scope"
                                        className="mt-1 accent-foreground"
                                        checked={scope === 'all'}
                                        onChange={() => setScope('all')}
                                    />
                                    <span className="flex flex-col">
                                        <span>
                                            Every environment of this row
                                        </span>
                                        <span className="type-small text-muted-foreground">
                                            {variable.targets
                                                .map((target) => target.label)
                                                .join(', ')}
                                        </span>
                                    </span>
                                </label>
                            </fieldset>
                        )}

                        <fieldset
                            className="flex flex-col gap-2"
                            disabled={shared && scope === 'only'}
                        >
                            <legend className="mb-2 type-label">
                                Environments
                            </legend>
                            {data.environments.map((environment) => (
                                <label
                                    key={environment.id}
                                    className="flex items-center gap-2 type-body"
                                >
                                    <Checkbox
                                        checked={targets.some(
                                            (target) =>
                                                target.environmentId ===
                                                environment.id,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggleEnvironment(
                                                environment.id,
                                                checked === true,
                                            )
                                        }
                                    />
                                    {environment.name}
                                </label>
                            ))}
                            <label className="flex items-center gap-2 type-body">
                                <Checkbox
                                    checked={previews !== undefined}
                                    onCheckedChange={(checked) =>
                                        togglePreviews(checked === true)
                                    }
                                />
                                All previews
                            </label>
                            {previews && (
                                <Input
                                    aria-label="Only for preview branches matching"
                                    placeholder="Only branches matching, e.g. feature/*"
                                    className="h-8 type-mono-small"
                                    value={previews.branchPattern ?? ''}
                                    onChange={(event) =>
                                        setTargets((current) =>
                                            current.map((target) =>
                                                target.allPreviews
                                                    ? {
                                                          ...target,
                                                          branchPattern:
                                                              event.target
                                                                  .value === ''
                                                                  ? null
                                                                  : event.target
                                                                        .value,
                                                      }
                                                    : target,
                                            ),
                                        )
                                    }
                                />
                            )}
                        </fieldset>
                    </div>

                    <Collapsible>
                        <CollapsibleTrigger className="group flex items-center gap-1 type-label text-muted-foreground hover:text-foreground">
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 transition-transform group-data-[state=open]:rotate-90"
                            />
                            Advanced
                        </CollapsibleTrigger>
                        <CollapsibleContent className="flex flex-col gap-2 pt-3">
                            <Label htmlFor="variable-exposure">
                                Available at
                            </Label>
                            <Select
                                value={exposure}
                                onValueChange={setExposure}
                            >
                                <SelectTrigger
                                    id="variable-exposure"
                                    className="sm:w-72"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="auto">
                                        Detected from the key
                                    </SelectItem>
                                    <SelectItem value="runtime">
                                        Runtime only
                                    </SelectItem>
                                    <SelectItem value="build">
                                        Build only
                                    </SelectItem>
                                    <SelectItem value="both">
                                        Build and runtime
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="type-small text-muted-foreground">
                                Build-time values reach builds as secrets and
                                changing them rebuilds the service.
                            </p>
                        </CollapsibleContent>
                    </Collapsible>

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
                            type="submit"
                            disabled={
                                busy ||
                                key === '' ||
                                keyInvalid ||
                                targets.length === 0
                            }
                        >
                            {editing ? 'Save' : 'Add variable'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
