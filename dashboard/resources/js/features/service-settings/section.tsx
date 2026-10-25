import { TriangleAlert } from 'lucide-react';
import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import {
    FloatingBar,
    FloatingBarButton,
} from '@/components/patterns/unsaved-bar';
import { SettingsPage as SharedSettingsPage } from '@/components/settings/settings-page';
import { useReportDirty } from './dirty';

type PageState = { id: string; active: boolean };

const PageContext = createContext<PageState>({ id: 'settings', active: true });

/** Which settings page a form belongs to, and whether it is the open one. */
export function SettingsPageProvider({
    id,
    active,
    children,
}: PageState & { children: ReactNode }) {
    return (
        <PageContext.Provider value={{ id, active }}>
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

/**
 * Unsaved edits of a settings form (direction §5.7, Laravel Cloud): the
 * floating "Unsaved changes · Reset · Save" bar at the bottom of the panel
 * instead of a Save button per section. Render it inside the `<form>`: Save
 * submits it. `stages` says what Save does ("Save" stages a change the
 * panel's StagedBar then offers for Deploy).
 */
export function SaveRow({
    dirty,
    processing,
    onReset,
    label = 'Save',
    message = 'Unsaved changes',
}: {
    dirty: boolean;
    processing: boolean;
    onReset: () => void;
    label?: string;
    message?: string;
}) {
    const { id, active } = useSettingsPage();
    useReportDirty(id, dirty, active);

    if (!dirty || !active) {
        return null;
    }

    return (
        <FloatingBar placement="inline" label="Unsaved changes">
            <TriangleAlert
                aria-hidden="true"
                className="size-3.5 shrink-0 opacity-80"
            />
            <span className="mr-2 truncate type-label">{message}</span>
            <FloatingBarButton onClick={onReset} disabled={processing}>
                Reset
            </FloatingBarButton>
            <FloatingBarButton
                type="submit"
                variant="primary"
                busy={processing}
                disabled={processing}
            >
                {label}
            </FloatingBarButton>
        </FloatingBar>
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
