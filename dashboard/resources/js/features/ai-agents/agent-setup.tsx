import {
    Eye,
    FileCode2,
    Rocket,
    ShieldCheck,
    SquareTerminal,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { CopyButton } from '@/components/copy-button';
import { CopyValue } from '@/components/copy-value';
import {
    LearnMore,
    SettingsBody,
    SettingsCard,
    SettingsRow,
    useDocsUrl,
} from '@/components/settings/settings-card';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { vocabulary } from '@/lib/vocabulary';
import { AGENT_ABILITIES, AGENT_CLIENTS, loginCommand } from './clients';

type Ability = App.Enums.ApiAbility;

/** The agent's abilities the role allows in `team` (`run` only when on). */
export function agentAbilities(
    team: App.Data.Api.ApiTeamOptionData | null,
    withRun: boolean,
): Ability[] {
    const available = new Set(
        (team?.abilities ?? [])
            .filter((option) => option.available || option.locked)
            .map((option) => option.value),
    );

    return AGENT_ABILITIES.filter(
        (ability) => available.has(ability) && (withRun || ability !== 'run'),
    );
}

/**
 * Settings → AI agents (docs/16 §7.6): three steps to connect an editor's
 * agent through `launchroom mcp`, what it can then do, and what to ask it.
 */
export function AgentSetup({
    team,
    cliInstall,
}: {
    team: App.Data.Api.ApiTeamOptionData | null;
    cliInstall: string;
}) {
    const docs = useDocsUrl();
    const runAllowed = agentAbilities(team, true).includes('run');
    const [withRun, setWithRun] = useState(true);
    const abilities = agentAbilities(team, withRun && runAllowed);

    return (
        <>
            <SettingsCard
                title="Connect your editor"
                setting="connect"
                footer={
                    <LearnMore href={docs('projects/ai-agents')}>
                        Set up an AI agent
                    </LearnMore>
                }
            >
                <SettingsBody className="flex flex-col gap-7">
                    <Step number={1} title="Install the CLI">
                        <p className="type-small text-muted-foreground">
                            The agent talks to launchroom through it. For macOS
                            and Linux.
                        </p>
                        <CopyValue value={cliInstall} label="install command" />
                    </Step>

                    <Step number={2} title="Sign in with the agent's access">
                        <p className="type-small text-muted-foreground">
                            Your browser asks you to approve these permissions.
                            The agent can never do more than your role in{' '}
                            {team?.name ?? 'this team'}.
                        </p>
                        <CopyValue
                            value={loginCommand(abilities)}
                            label="sign-in command"
                        />
                        <ul
                            className="flex flex-wrap gap-1.5"
                            aria-label="Permissions"
                        >
                            {abilities.map((ability) => (
                                <li key={ability}>
                                    <Badge variant="secondary">
                                        {vocabulary.ApiAbility[ability].label}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                        {runAllowed && (
                            <label className="flex items-start gap-3 rounded-lg border bg-card px-3.5 py-3">
                                <Switch
                                    checked={withRun}
                                    onCheckedChange={setWithRun}
                                    className="mt-0.5"
                                />
                                <span className="flex flex-col gap-0.5">
                                    <span className="type-label">
                                        Let it run commands
                                    </span>
                                    <span className="type-small text-muted-foreground">
                                        Migrations, cache clears and checks in a
                                        copy of the live app. Most editors ask
                                        you before each one.
                                    </span>
                                </span>
                            </label>
                        )}
                        <p className="flex items-start gap-2 type-small text-muted-foreground">
                            <ShieldCheck
                                className="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            It never sees secret values or exports data. Logs
                            and command output reach it marked as data, not as
                            instructions.
                        </p>
                    </Step>

                    <Step number={3} title="Add launchroom to your editor">
                        <ClientPicker />
                    </Step>
                </SettingsBody>
            </SettingsCard>

            <SettingsCard
                title="What it can do"
                setting="capabilities"
                description="Each needs the permission shown with it."
            >
                <Capability
                    icon={Eye}
                    label="Understand your projects"
                    description="Services and their status, deployments, logs, request metrics, and what happened to one request from its ray."
                    ability="read"
                />
                <Capability
                    icon={Rocket}
                    label="Ship and follow"
                    description="Deploy a branch or commit, watch it go live, redeploy, roll back or cancel."
                    ability="deploy"
                />
                <Capability
                    icon={Wrench}
                    label="Fix what failed"
                    description="Read why a deploy failed with the failing lines, apply the fix launchroom suggests, set a missing variable."
                    ability="fix"
                />
                <Capability
                    icon={SquareTerminal}
                    label="Run commands"
                    description="One-off commands in a copy of the live app, with the output and exit code."
                    ability="run"
                />
                <Capability
                    icon={FileCode2}
                    label="Check launchroom.json"
                    description="Validate the config file against an environment before you commit it."
                    ability="read"
                />
            </SettingsCard>

            <SettingsCard
                title="Try asking"
                setting="prompts"
                description="In your editor's chat, once launchroom shows up as connected."
            >
                {PROMPTS.map((prompt) => (
                    <SettingsRow
                        key={prompt}
                        label={<span className="font-normal">{prompt}</span>}
                        inline
                    >
                        <CopyButton value={prompt} label="prompt" />
                    </SettingsRow>
                ))}
            </SettingsCard>
        </>
    );
}

const PROMPTS = [
    'Why did my last deploy fail? Fix it and deploy again.',
    'Ship this branch to staging and tell me when it’s live.',
    'Are there new errors in production since the last deploy?',
    'Run the migrations on production.',
    'Is the preview of my pull request working?',
];

function Step({
    number,
    title,
    children,
}: {
    number: number;
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="grid grid-cols-[28px_minmax(0,1fr)] gap-x-3.5">
            <span
                aria-hidden="true"
                className="flex size-7 items-center justify-center rounded-full border bg-card type-mono-small"
            >
                {number}
            </span>
            <div className="flex min-w-0 flex-col gap-3">
                <h3 className="type-label leading-7">
                    <span className="sr-only">Step {number}: </span>
                    {title}
                </h3>
                {children}
            </div>
        </section>
    );
}

function ClientPicker() {
    return (
        <Tabs defaultValue={AGENT_CLIENTS[0].id} className="gap-3">
            <div className="-mx-1 overflow-x-auto px-1 pb-1">
                <TabsList aria-label="Editor">
                    {AGENT_CLIENTS.map((client) => (
                        <TabsTrigger key={client.id} value={client.id}>
                            {client.name}
                        </TabsTrigger>
                    ))}
                </TabsList>
            </div>
            {AGENT_CLIENTS.map((client) => (
                <TabsContent
                    key={client.id}
                    value={client.id}
                    className="flex flex-col gap-3"
                >
                    <p className="type-small text-muted-foreground">
                        Run this in your project folder:
                    </p>
                    <CopyValue
                        value={client.command}
                        label={`${client.name} command`}
                    />
                    <p className="type-small text-muted-foreground">
                        Or add it to{' '}
                        <code className="type-mono-small text-foreground">
                            {client.file}
                        </code>{' '}
                        yourself:
                    </p>
                    <Snippet value={client.snippet} label={client.file} />
                    {client.note && (
                        <p className="type-small text-muted-foreground">
                            {client.note}
                        </p>
                    )}
                </TabsContent>
            ))}
        </Tabs>
    );
}

function Snippet({ value, label }: { value: string; label: string }) {
    return (
        <div className="relative rounded-md border bg-muted">
            <pre className="overflow-x-auto px-3 py-2.5 pr-11 type-mono-small leading-5">
                {value}
            </pre>
            <CopyButton
                value={value}
                label={label}
                className="absolute top-1.5 right-1.5 size-7 text-foreground [&_svg]:size-4"
            />
        </div>
    );
}

function Capability({
    icon: Icon,
    label,
    description,
    ability,
}: {
    icon: typeof Eye;
    label: string;
    description: string;
    ability: Ability;
}) {
    return (
        <SettingsRow
            label={label}
            description={description}
            align="start"
            media={
                <span className="flex size-9 items-center justify-center rounded-lg bg-recessed">
                    <Icon
                        className="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                </span>
            }
        >
            {/* On phones the badge goes under the text, lined up with it past the icon. */}
            <span className="pl-12 sm:pl-0">
                <Badge variant="outline">
                    {vocabulary.ApiAbility[ability].label}
                </Badge>
            </span>
        </SettingsRow>
    );
}
