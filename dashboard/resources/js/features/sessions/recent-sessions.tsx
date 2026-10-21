import { History, PlayCircle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Section } from '@/components/patterns/section';
import { SidePanel } from '@/components/patterns/sheet';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { AnsiText } from '@/features/logs/ansi-text';
import { endSession, fetchRecording, RequestFailed } from './api';
import { duration } from './format';
import { PasswordPrompt } from './password-prompt';

type Item = App.Data.Sessions.SessionHistoryItemData;

/**
 * "Recent sessions" of a service (docs/08 §15): who, which replica, when,
 * how long and why it ended. Owners/admins see everyone's and can end
 * open ones; recordings open in a side panel.
 */
export function RecentSessions({
    sessions,
    onChanged,
}: {
    sessions: Item[];
    onChanged: () => void;
}) {
    const [recording, setRecording] = useState<Item | null>(null);

    if (sessions.length === 0) {
        return null;
    }

    return (
        <Section title="Recent sessions" level={3}>
            <ul className="flex flex-col divide-y rounded-xl border bg-card">
                {sessions.map((item) => (
                    <li
                        key={item.id}
                        className="flex min-h-14 items-center gap-3 px-4 py-2.5"
                    >
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-recessed text-muted-foreground">
                            <History aria-hidden="true" className="size-3.5" />
                        </span>
                        <div className="flex min-w-0 flex-1 flex-col">
                            <p className="truncate type-body">
                                <b className="font-medium">
                                    {item.isMine ? 'You' : item.userName}
                                </b>{' '}
                                <span className="text-muted-foreground">
                                    on {item.replicaLabel}
                                    {item.serverName
                                        ? ` · ${item.serverName}`
                                        : ''}
                                    {item.shell
                                        ? ` · ${item.shell.replace('/bin/', '')}`
                                        : ''}
                                </span>
                            </p>
                            <p className="truncate type-small text-muted-foreground">
                                {item.status === 'ended'
                                    ? [
                                          duration(item.durationSeconds),
                                          item.endReason,
                                      ]
                                          .filter(Boolean)
                                          .join(' · ')
                                    : 'Open now'}
                            </p>
                        </div>
                        <span className="hidden shrink-0 type-small text-muted-foreground sm:inline">
                            <TimeAgo value={item.openedAt} />
                        </span>
                        {item.status !== 'ended' && (
                            <Status
                                state="active"
                                label="Open"
                                className="shrink-0"
                            />
                        )}
                        {item.canEnd && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    void endSession(item.id).finally(onChanged);
                                }}
                            >
                                End
                            </Button>
                        )}
                        {item.hasRecording && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setRecording(item)}
                            >
                                <PlayCircle aria-hidden="true" />
                                Recording
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
            <RecordingPanel
                item={recording}
                onClose={() => setRecording(null)}
            />
        </Section>
    );
}

function RecordingPanel({
    item,
    onClose,
}: {
    item: Item | null;
    onClose: () => void;
}) {
    return (
        <SidePanel
            open={item !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            title="Console recording"
            description={
                item
                    ? `${item.isMine ? 'You' : item.userName} on ${item.replicaLabel} · output only, secrets masked`
                    : undefined
            }
            size="lg"
        >
            {item && (
                <RecordingBody key={item.id} item={item} onCancel={onClose} />
            )}
        </SidePanel>
    );
}

function RecordingBody({
    item,
    onCancel,
}: {
    item: Item;
    onCancel: () => void;
}) {
    const [lines, setLines] = useState<string[] | null>(null);
    const [asking, setAsking] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(
        async (password?: string) => {
            setBusy(true);
            setError(null);

            try {
                const result = await fetchRecording(item.id, password);
                setLines(result.lines);
                setAsking(false);
            } catch (failure) {
                if (
                    failure instanceof RequestFailed &&
                    failure.status === 423
                ) {
                    setAsking(true);
                } else {
                    setError(
                        failure instanceof Error
                            ? failure.message
                            : 'Couldn’t load the recording.',
                    );
                }
            } finally {
                setBusy(false);
            }
        },
        [item.id],
    );

    useEffect(() => {
        void load();
    }, [load]);

    if (asking) {
        return (
            <PasswordPrompt
                id="recording-password"
                busy={busy}
                error={error}
                action="Show recording"
                onSubmit={(password) => void load(password)}
                onCancel={onCancel}
            />
        );
    }

    if (error) {
        return <p className="type-small text-status-error">{error}</p>;
    }

    if (lines === null) {
        return (
            <p className="type-small text-muted-foreground">
                Loading the recording…
            </p>
        );
    }

    return (
        <pre className="overflow-x-auto rounded-xl bg-recessed p-4 type-mono-small leading-5 whitespace-pre-wrap">
            {lines.length === 0
                ? 'Nothing was printed in this session.'
                : lines.map((line, index) => (
                      <div key={index}>
                          <AnsiText text={line} />
                      </div>
                  ))}
        </pre>
    );
}
