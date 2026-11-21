import { FileJson, Play, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { Section } from '@/components/patterns/section';
import { LearnMore, useDocsUrl } from '@/components/settings/settings-card';
import { ServiceGlyph } from '@/components/service-glyph';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatArgv } from './argv';

type Command = App.Data.Commands.CommandData;
type Catalogue = App.Data.Commands.CommandCatalogueData;

/**
 * The commands a service lists (docs/08 §22): the framework's from the
 * live build, then the team's from launchroom.json, as rows of label, one
 * sentence and the argv. A command that changes data says so (icon and
 * words) and asks before it runs. Clicking the argv puts it into the
 * command bar instead of running it.
 */
export function Catalogue({
    catalogue,
    logo,
    environment,
    disabled,
    onRun,
    onFill,
}: {
    catalogue: Catalogue;
    logo: string;
    environment: string;
    disabled: boolean;
    onRun: (command: Command) => Promise<unknown>;
    onFill: (command: Command) => void;
}) {
    const docs = useDocsUrl();
    const framework = catalogue.commands.filter(
        (command) => command.source === 'framework',
    );
    const config = catalogue.commands.filter(
        (command) => command.source === 'config',
    );
    const rows = { environment, disabled, onRun, onFill };

    return (
        <>
            {catalogue.framework &&
                (framework.length > 0 || catalogue.awaitingBuild) && (
                    <Section
                        level={3}
                        title={
                            <span className="flex items-center gap-2.5">
                                <ServiceGlyph logo={logo} size="sm" />
                                {catalogue.framework} commands
                            </span>
                        }
                    >
                        {framework.length > 0 ? (
                            <CommandRows commands={framework} {...rows} />
                        ) : (
                            <p className="rounded-xl border border-dashed px-4 py-5 type-body text-muted-foreground">
                                {catalogue.framework} commands show up here
                                after the next build.
                            </p>
                        )}
                    </Section>
                )}
            {config.length > 0 && (
                <Section
                    level={3}
                    title={
                        <span className="flex items-center gap-2.5">
                            <span className="flex size-5 items-center justify-center rounded-[5px] bg-recessed text-muted-foreground">
                                <FileJson
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </span>
                            Your commands
                        </span>
                    }
                    actions={
                        <span className="type-small text-muted-foreground">
                            From launchroom.json
                        </span>
                    }
                >
                    <CommandRows commands={config} {...rows} />
                </Section>
            )}
            {catalogue.commands.length === 0 && !catalogue.awaitingBuild && (
                <p className="type-small text-muted-foreground">
                    Commands you run often can get a name in launchroom.json,
                    and they show up here.{' '}
                    <LearnMore href={docs('projects/run-a-command')}>
                        Name a command
                    </LearnMore>
                </p>
            )}
        </>
    );
}

function CommandRows({
    commands,
    ...row
}: {
    commands: Command[];
    environment: string;
    disabled: boolean;
    onRun: (command: Command) => Promise<unknown>;
    onFill: (command: Command) => void;
}) {
    return (
        <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
            {commands.map((command) => (
                <CommandRow
                    key={`${command.source}-${command.name}`}
                    command={command}
                    {...row}
                />
            ))}
        </ul>
    );
}

function CommandRow({
    command,
    environment,
    disabled,
    onRun,
    onFill,
}: {
    command: Command;
    environment: string;
    disabled: boolean;
    onRun: (command: Command) => Promise<unknown>;
    onFill: (command: Command) => void;
}) {
    const [busy, setBusy] = useState(false);
    const argv = formatArgv(command.argv);
    const run = async () => {
        setBusy(true);

        try {
            await onRun(command);
        } finally {
            setBusy(false);
        }
    };
    const button = (
        <Button
            size="sm"
            variant="outline"
            disabled={disabled || busy}
            onClick={command.danger ? undefined : () => void run()}
            aria-label={`Run ${command.label}`}
        >
            {busy ? <Spinner /> : <Play aria-hidden="true" />}
            Run
        </Button>
    );

    return (
        <li className="group flex items-start gap-4 px-4 py-3.5">
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <span className="type-label">{command.label}</span>
                    {command.danger && (
                        <span className="inline-flex items-center gap-1 type-small text-status-progress">
                            <TriangleAlert
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            Changes data
                        </span>
                    )}
                </p>
                {command.description && (
                    <p className="type-small text-muted-foreground">
                        {command.description}
                    </p>
                )}
                <button
                    type="button"
                    onClick={() => onFill(command)}
                    disabled={disabled}
                    title="Put it in the command bar"
                    className="mt-0.5 -ml-1.5 w-fit max-w-full truncate rounded-md px-1.5 py-0.5 text-left type-mono-small text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none"
                >
                    {argv}
                </button>
            </div>
            <div className="shrink-0 pt-0.5">
                {command.danger ? (
                    <ConfirmPopover
                        title={`Run “${command.label}” in ${environment}?`}
                        body={
                            <code className="block rounded-md bg-recessed px-2.5 py-1.5 type-mono-small break-all">
                                {argv}
                            </code>
                        }
                        consequence={`It changes data in ${environment}. The output opens when it starts.`}
                        confirmLabel="Run"
                        onConfirm={run}
                    >
                        {button}
                    </ConfirmPopover>
                ) : (
                    button
                )}
            </div>
        </li>
    );
}
