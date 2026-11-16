import { router } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { createContext, useContext, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import {
    FloatingBar,
    FloatingBarButton,
    FloatingSaveSplit,
} from '@/components/patterns/unsaved-bar';
import type { SaveChoice } from '@/components/patterns/unsaved-bar';
import { revealSetting } from '@/components/settings/settings-shell';
import { SettingsPage as SharedSettingsPage } from '@/components/settings/settings-page';
import { deployStaged } from '@/features/staged-changes/staged-changes-sheet';
import { useStagedChanges } from '@/features/staged-changes/use-staged-changes';
import { useReportDirty } from './dirty';

type PageState = {
    id: string;
    active: boolean;
    /** The service the page belongs to: [project, environment, service]. */
    route?: readonly [string, string, string];
    /** Its name, for "web restarts now". */
    serviceName?: string;
};

const PageContext = createContext<PageState>({ id: 'settings', active: true });

/** Which settings page a form belongs to, and whether it is the open one. */
export function SettingsPageProvider({
    id,
    active,
    route,
    serviceName,
    children,
}: PageState & { children: ReactNode }) {
    return (
        <PageContext.Provider value={{ id, active, route, serviceName }}>
            {children}
        </PageContext.Provider>
    );
}

export function useSettingsPage(): PageState {
    return useContext(PageContext);
}

/**
 * One page of the Settings tab: the shared `SettingsPage` (title, one
 * sentence, then its cards 32 px apart) under the panel's headings.
 */
export function SettingsPage({
    title,
    description,
    actions,
    children,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { id } = useSettingsPage();

    return (
        <SharedSettingsPage
            level={3}
            labelId={`settings-${id}-title`}
            title={title}
            description={description}
            actions={actions}
        >
            {children}
        </SharedSettingsPage>
    );
}

/** A form field's label and the `data-setting` row it lives in. */
export type FieldLabels = Record<
    string,
    readonly [label: string, anchor: string]
>;

/**
 * Which labelled fields differ from `baseline` (top-level keys, deep
 * compare), in the order of `labels`.
 */
export function changedFields(
    fields: Record<string, unknown>,
    baseline: Record<string, unknown>,
    labels: FieldLabels,
): { key: string; label: string; anchor: string }[] {
    return Object.entries(labels)
        .filter(
            ([key]) =>
                JSON.stringify(fields[key]) !== JSON.stringify(baseline[key]),
        )
        .map(([key, [label, anchor]]) => ({ key, label, anchor }));
}

/** "Replicas changed", "3 settings changed", or the generic line. */
export function unsavedMessage(changed: { label: string }[]): string {
    if (changed.length === 0) {
        return 'Unsaved changes';
    }

    return changed.length === 1
        ? `${changed[0].label} changed`
        : `${changed.length} settings changed`;
}

/**
 * Submits the bar's form, then applies everything staged in the
 * environment once that save went through (the request's own events, so a
 * validation error applies nothing).
 */
function submitThenApply(
    bar: HTMLElement | null,
    project: string,
    environment: string,
    onDone: () => void,
): boolean {
    const form = bar?.closest('form');

    if (!form) {
        return false;
    }

    // `before` fires synchronously inside the submit handler's visit.
    const started: { id: string | null } = { id: null };
    const offBefore = router.on('before', (event) => {
        if (event.detail.visit.method !== 'get') {
            started.id = event.detail.visit.id;
        }
    });
    form.requestSubmit();
    offBefore();
    const visitId = started.id;

    if (visitId === null) {
        return false;
    }

    let saved = false;
    const offSuccess = router.on('success', (event) => {
        if (event.detail.visitId === visitId) {
            saved = true;
        }
    });
    const offFinish = router.on('finish', (event) => {
        if (event.detail.visit.id !== visitId) {
            return;
        }

        offSuccess();
        offFinish();

        if (saved) {
            deployStaged(project, environment, { onFinish: onDone });
        } else {
            onDone();
        }
    });

    return true;
}

/**
 * Unsaved edits of a settings form (direction §5.7, Laravel Cloud): the
 * floating bar at the bottom of the panel instead of a Save button per
 * section. Render it inside the `<form>`: Save submits it. It names what
 * changed ("Replicas changed"; a click scrolls there) when given the form's
 * `fields` and their `labels`.
 *
 * `stages` (the form stages a change, docs/06 §4): Save is a split button,
 * "Save and apply" (save, then apply everything staged in the environment)
 * or "Save for later" (stays staged for the next deploy or Apply). Without
 * the right to apply, or with broken references, "Save for later" leads.
 */
export function SaveRow({
    dirty,
    processing,
    onReset,
    label = 'Save',
    stages,
    fields,
    labels,
}: {
    dirty: boolean;
    processing: boolean;
    onReset: () => void;
    label?: string;
    /** What applying does to this service: it restarts or rebuilds. */
    stages?: 'restart' | 'rebuild';
    fields?: Record<string, unknown>;
    labels?: FieldLabels;
}) {
    const { id, active, route, serviceName } = useSettingsPage();
    useReportDirty(id, dirty, active);
    const bar = useRef<HTMLSpanElement>(null);
    const [applying, setApplying] = useState(false);
    const [baseline, setBaseline] = useState(fields);

    // The last clean state is what "changed" compares against (the initial
    // values, then whatever Save or Reset left).
    if (!dirty && baseline !== fields) {
        setBaseline(fields);
    }

    if (!dirty || !active) {
        return null;
    }

    const changed =
        fields && labels && baseline
            ? changedFields(fields, baseline, labels)
            : [];
    const message = unsavedMessage(changed);
    const busy = processing || applying;

    const reveal = () => {
        const page = bar.current?.closest('[data-settings-page]');

        if (page && changed[0]) {
            revealSetting(page, [changed[0].anchor]);
        }
    };

    return (
        <FloatingBar placement="inline" label="Unsaved changes">
            <span ref={bar} className="contents" />
            <TriangleAlert
                aria-hidden="true"
                className="size-3.5 shrink-0 opacity-80"
            />
            {changed.length > 0 ? (
                <button
                    type="button"
                    onClick={reveal}
                    title={changed.map((field) => field.label).join(', ')}
                    className="mr-2 truncate rounded-sm type-label underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none"
                >
                    {message}
                </button>
            ) : (
                <span className="mr-2 truncate type-label">{message}</span>
            )}
            <FloatingBarButton onClick={onReset} disabled={busy}>
                Reset
            </FloatingBarButton>
            {stages && route ? (
                <StagingSave
                    route={route}
                    name={serviceName ?? 'This service'}
                    stages={stages}
                    busy={busy}
                    onApply={() => {
                        setApplying(
                            submitThenApply(
                                bar.current,
                                route[0],
                                route[1],
                                () => setApplying(false),
                            ),
                        );
                    }}
                    onSave={() => bar.current?.closest('form')?.requestSubmit()}
                />
            ) : (
                <FloatingBarButton
                    type="submit"
                    variant="primary"
                    busy={processing}
                    disabled={processing}
                >
                    {label}
                </FloatingBarButton>
            )}
        </FloatingBar>
    );
}

/** The two ways to save a staged change, worded for this service. */
function StagingSave({
    route,
    name,
    stages,
    busy,
    onApply,
    onSave,
}: {
    route: readonly [string, string, string];
    name: string;
    stages: 'restart' | 'rebuild';
    busy: boolean;
    onApply: () => void;
    onSave: () => void;
}) {
    const [project, environment] = route;
    const { data } = useStagedChanges(project, environment);
    const others = data?.count ?? 0;
    const blocked = !data
        ? 'Checking what is staged'
        : !data.canDeploy
          ? 'Applying changes needs the developer role'
          : data.blockers.length > 0
            ? 'Fix the broken references first'
            : null;
    const applyHint =
        blocked ??
        (data?.neverDeployed
            ? 'Deploys this environment for the first time.'
            : `${name} ${stages === 'rebuild' ? 'rebuilds' : 'restarts'} now${
                  others > 0
                      ? `, and ${others === 1 ? 'the other staged change applies' : `the ${others} other staged changes apply`} too.`
                      : '.'
              }`);
    const apply: SaveChoice = {
        id: 'apply',
        label: 'Save and apply',
        hint: applyHint,
        disabled: blocked !== null,
        onSelect: onApply,
    };
    const later: SaveChoice = {
        id: 'later',
        label: 'Save for later',
        hint: 'It applies with the next deploy, or when you apply.',
        onSelect: onSave,
    };

    return (
        <FloatingSaveSplit
            choices={[apply, later]}
            defaultId={
                blocked !== null || data?.neverDeployed ? 'later' : 'apply'
            }
            busy={busy}
        />
    );
}

/** A label above a value, for read-only facts. */
export function Fact({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1">
            <dt className="type-label text-muted-foreground">{label}</dt>
            <dd className="min-w-0 type-body break-words">{children}</dd>
        </div>
    );
}

/** A 32 px recessed icon tile (rows, facts, steps). */
export function IconTile({
    children,
    className = '',
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={`flex size-8 shrink-0 items-center justify-center rounded-lg border bg-recessed text-muted-foreground [&_svg]:size-4 ${className}`}
        >
            {children}
        </span>
    );
}
