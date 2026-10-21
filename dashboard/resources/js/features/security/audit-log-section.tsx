import { router } from '@inertiajs/react';
import { KeyRound, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CopyValue } from '@/components/copy-value';
import { SidePanel, SheetSection } from '@/components/patterns/sheet';
import { StatusPill } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { AddAuditSink } from '@/features/security/add-audit-sink';
import { AuditSinkRow } from '@/features/security/audit-sink-row';
import { AuditVerificationLine } from '@/features/security/audit-verification-line';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { store as verify } from '@/routes/audit-verification';

type Kind = App.Enums.Audit.AuditSinkKind;

/** Flash data that arrives once (the new webhook's signing secret). */
function useFlash<T>(key: string): T | null {
    const [value, setValue] = useState<T | null>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const flash = (event as CustomEvent).detail?.flash as
                    | Record<string, unknown>
                    | undefined;

                if (flash && key in flash) {
                    setValue(flash[key] as T);
                }
            }),
        [key],
    );

    return value;
}

/**
 * Settings → Security → Audit log (docs/09 R34, D50): whether the chain
 * and its signed checkpoints hold, and where copies go so it can be
 * checked outside launchroom.
 */
export function AuditLogSection({
    overview,
}: {
    overview: App.Data.Security.AuditLogOverviewData;
}) {
    const [adding, setAdding] = useState(false);
    const [kind, setKind] = useState<Kind | null>(null);
    const [verifying, setVerifying] = useState(false);
    const secret = useFlash<{ sink: string; secret: string }>(
        'auditSigningSecret',
    );
    const v = overview.verification;

    return (
        <>
            <SettingsCard
                title="Audit log"
                description="Every change is chained to the one before it and signed every 10 minutes, so edits and deletions show."
                actions={
                    v === null ? (
                        <StatusPill state="idle" label="Not checked yet" />
                    ) : v.ok ? (
                        <StatusPill state="active" label="Verified" />
                    ) : (
                        <StatusPill state="failed" label="Check failed" />
                    )
                }
            >
                <SettingsRow
                    label={<AuditVerificationLine verification={v} />}
                    description={
                        v === null ? (
                            `${overview.entries.toLocaleString()} entries so far.`
                        ) : (
                            <span>
                                {v.ok
                                    ? `${overview.entries.toLocaleString()} entries. `
                                    : v.problemEntry !== null
                                      ? `First problem at entry #${v.problemEntry}. `
                                      : ''}
                                Checked <TimeAgo value={v.checkedAt} />
                                {v.mode === 'incremental'
                                    ? ' since the last checkpoint'
                                    : ' from the first entry'}
                                .
                            </span>
                        )
                    }
                    align="start"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={verifying}
                        onClick={() =>
                            router.post(
                                verify().url,
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setVerifying(true),
                                    onFinish: () => setVerifying(false),
                                },
                            )
                        }
                    >
                        {verifying ? 'Checking…' : 'Verify now'}
                    </Button>
                </SettingsRow>
                <SettingsRow
                    label="Signing key"
                    description={
                        <span className="flex flex-col gap-0.5">
                            <span>
                                Checks forwarded copies outside launchroom.
                                {overview.lastCheckpointAt && (
                                    <>
                                        {' '}
                                        Last signed{' '}
                                        <TimeAgo
                                            value={overview.lastCheckpointAt}
                                        />
                                        .
                                    </>
                                )}
                            </span>
                        </span>
                    }
                    align="start"
                >
                    <CopyValue value={overview.publicKey} label="signing key" />
                </SettingsRow>
            </SettingsCard>

            {secret && (
                <SettingsCard
                    title="Signing secret"
                    description="Store it in your receiver now. It won't be shown again."
                >
                    <SettingsRow
                        label="Verify each batch with it"
                        media={
                            <span className="flex size-8 items-center justify-center rounded-md border bg-card text-muted-foreground">
                                <KeyRound
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </span>
                        }
                        description={
                            <span className="type-mono-small break-all text-foreground">
                                {secret.secret}
                            </span>
                        }
                        align="start"
                    >
                        <CopyValue
                            value={secret.secret}
                            label="signing secret"
                        />
                    </SettingsRow>
                </SettingsCard>
            )}

            <SettingsCard
                title="Copies outside launchroom"
                description="Each entry is sent as it happens. A copy someone can't edit from here is what makes the log trustworthy."
                actions={
                    overview.sinks.length > 0 &&
                    overview.canAdd && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                setKind(null);
                                setAdding(true);
                            }}
                        >
                            <Plus aria-hidden="true" />
                            Add another
                        </Button>
                    )
                }
            >
                {overview.sinks.length === 0 ? (
                    <div className="px-5 py-5">
                        <AddAuditSink kind={kind} onKindChange={setKind} />
                    </div>
                ) : (
                    overview.sinks.map((sink) => (
                        <AuditSinkRow key={sink.id} sink={sink} />
                    ))
                )}
            </SettingsCard>

            <SidePanel
                open={adding}
                onOpenChange={setAdding}
                size="md"
                title="Send a copy of the audit log"
                description="New destinations start with the first entry and catch up."
            >
                <SheetSection>
                    <AddAuditSink
                        kind={kind}
                        onKindChange={setKind}
                        onAdded={() => setAdding(false)}
                    />
                </SheetSection>
            </SidePanel>
        </>
    );
}
