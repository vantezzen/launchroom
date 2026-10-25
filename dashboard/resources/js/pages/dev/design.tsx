import { Head } from '@inertiajs/react';
import { FolderGit2, GitPullRequest, Rocket } from 'lucide-react';
import { useState } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { toast } from 'sonner';
import { Logo } from '@/components/logo';
import type { LogoSize } from '@/components/logo';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { EmptyState } from '@/components/patterns/empty-state';
import { LiveWait } from '@/components/patterns/live-wait';
import { OptionCards } from '@/components/patterns/option-cards';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { SheetSection, SidePanel } from '@/components/patterns/sheet';
import { Stepper } from '@/components/patterns/stepper';
import { TopBarActions } from '@/components/patterns/top-bar';
import { StagedBar, UnsavedBar } from '@/components/patterns/unsaved-bar';
import { Wizard } from '@/components/patterns/wizard';
import { Status, StatusPill } from '@/components/status';
import type { StatusState } from '@/components/status';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

/**
 * Other workstreams register their primitives here without touching this
 * file: any `components/**\/*.design.tsx` whose default export is a
 * component is rendered as its own section (title from `export const
 * title`).
 */
const extensions = import.meta.glob<{
    default: ComponentType;
    title?: string;
}>('../../components/**/*.design.tsx', { eager: true });

function Swatch({ name, token }: { name: string; token: string }) {
    return (
        <div className="flex items-center gap-3">
            <span
                className="size-9 shrink-0 rounded-md border"
                style={{ background: `var(${token})` }}
            />
            <span className="flex flex-col">
                <span className="type-label">{name}</span>
                <span className="type-mono-small text-muted-foreground">
                    {token}
                </span>
            </span>
        </div>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-3 border-t py-4 first:border-t-0 sm:grid-cols-[160px_minmax(0,1fr)]">
            <p className="type-label text-muted-foreground">{label}</p>
            <div className="flex min-w-0 flex-wrap items-center gap-3">
                {children}
            </div>
        </div>
    );
}

const statuses: { state: StatusState; label: string; detail?: string }[] = [
    { state: 'active', label: 'Live' },
    { state: 'active', label: 'Online' },
    { state: 'progress', label: 'Deploying', detail: 'Building, 41s' },
    { state: 'staged', label: 'Ready to promote' },
    {
        state: 'warning',
        label: 'Last deploy failed',
        detail: 'still serving 3b7d5e1',
    },
    { state: 'failed', label: 'Unreachable', detail: 'for 8 min' },
    { state: 'idle', label: 'Superseded' },
];

const logos = [
    'nextjs',
    'laravel',
    'node',
    'python',
    'postgres',
    'mysql',
    'redis',
    'valkey',
    'mongodb',
    'docker',
    'github',
    'hetzner',
    'cloudflare',
    'worker',
    'cron',
    'unknown-kind',
];

export default function DesignSheet() {
    const [machine, setMachine] = useState<string | null>('minipc');
    const [mode, setMode] = useState<string | null>('builtin');
    const [replicas, setReplicas] = useState(2);
    const [step, setStep] = useState(1);
    const [sheet, setSheet] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [toggle, setToggle] = useState(true);

    return (
        <>
            <Head title="Design primitives" />
            <TopBarActions>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setSheet(true)}
                >
                    Open sheet
                </Button>
            </TopBarActions>
            <Page>
                <PageHeader
                    title="Design primitives"
                    description="Every building block in the current theme. Local development only."
                    actions={
                        <>
                            <Button variant="outline">Secondary</Button>
                            <Button>Primary action</Button>
                        </>
                    }
                />

                <Section
                    title="Colour"
                    description="Monochrome chrome; colour for status and data only."
                >
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Swatch name="Frame" token="--frame" />
                        <Swatch name="Background" token="--background" />
                        <Swatch name="Card" token="--card" />
                        <Swatch name="Recessed" token="--recessed" />
                        <Swatch name="Border" token="--border" />
                        <Swatch name="Border strong" token="--border-strong" />
                        <Swatch name="Hover" token="--hover" />
                        <Swatch name="Faint" token="--faint" />
                        <Swatch name="Signal" token="--signal" />
                        <Swatch name="Signal soft" token="--signal-soft" />
                        <Swatch name="OK" token="--status-ok" />
                        <Swatch name="Progress" token="--status-progress" />
                        <Swatch name="Error" token="--status-error" />
                        <Swatch name="Idle" token="--status-idle" />
                    </div>
                    <div className="flex flex-wrap gap-2 pt-2">
                        {[1, 2, 3, 4, 5, 6, 7, 8].map((slot) => (
                            <span
                                key={slot}
                                className="flex items-center gap-1.5 type-small text-muted-foreground"
                            >
                                <span
                                    className="size-3 rounded-sm"
                                    style={{
                                        background: `var(--chart-${slot})`,
                                    }}
                                />
                                chart-{slot}
                            </span>
                        ))}
                    </div>
                </Section>

                <Section title="Type roles">
                    <Card>
                        <CardContent className="flex flex-col gap-3">
                            <p className="type-display">Display 32: heroes</p>
                            <p className="type-title">Title 28: page titles</p>
                            <p className="type-stat">34%: stat 24</p>
                            <p className="type-heading">
                                Heading 16: sections and cards
                            </p>
                            <p className="type-body">
                                Body 14: default UI text, one sentence per
                                description.
                            </p>
                            <p className="type-label">
                                Label 13: forms, tabs, chips
                            </p>
                            <p className="type-small text-muted-foreground">
                                Small 12: meta text and legends
                            </p>
                            <p className="type-mono">
                                mono 13: api.shop.internal:8080
                            </p>
                            <p className="type-mono-small text-muted-foreground">
                                mono-small 12: 3b7d5e1
                            </p>
                        </CardContent>
                    </Card>
                </Section>

                <Section title="Controls">
                    <Card>
                        <CardContent>
                            <Row label="Buttons">
                                <Button>Deploy</Button>
                                <Button variant="outline">Redeploy</Button>
                                <Button variant="ghost">Cancel</Button>
                                <Button variant="secondary">Secondary</Button>
                                <Button variant="destructive">Remove</Button>
                                <Button variant="link">Learn more</Button>
                                <Button size="sm">Small</Button>
                            </Row>
                            <Row label="Chips">
                                <Badge>Runtime</Badge>
                                <Badge variant="outline">Production only</Badge>
                                <Badge variant="ok">Succeeded</Badge>
                                <Badge variant="progress">1 warning</Badge>
                                <Badge variant="destructive">1 error</Badge>
                                <Badge variant="signal">Default</Badge>
                                <Badge variant="staged">New</Badge>
                            </Row>
                            <Row label="Inputs">
                                <div className="grid w-full max-w-sm gap-1.5">
                                    <Label htmlFor="ds-name">Name</Label>
                                    <Input
                                        id="ds-name"
                                        placeholder="e.g. shop"
                                    />
                                </div>
                                <Textarea
                                    className="max-w-sm"
                                    placeholder="Paste a .env file"
                                />
                            </Row>
                            <Row label="Switch, progress">
                                <Switch
                                    checked={toggle}
                                    onCheckedChange={setToggle}
                                    aria-label="Example switch"
                                />
                                <Progress
                                    value={64}
                                    tone="progress"
                                    className="w-40"
                                />
                                <Progress
                                    value={38}
                                    tone="signal"
                                    className="w-40"
                                />
                            </Row>
                            <Row label="Tabs">
                                <Tabs defaultValue="hour">
                                    <TabsList>
                                        <TabsTrigger value="hour">
                                            Hour
                                        </TabsTrigger>
                                        <TabsTrigger value="day">
                                            Day
                                        </TabsTrigger>
                                        <TabsTrigger value="week">
                                            Week
                                        </TabsTrigger>
                                    </TabsList>
                                    <TabsContent
                                        value="hour"
                                        className="type-small text-muted-foreground"
                                    >
                                        Segmented control, same as the project
                                        tabs.
                                    </TabsContent>
                                </Tabs>
                            </Row>
                        </CardContent>
                    </Card>
                </Section>

                <Section
                    title="Status"
                    description="One word per state, always with an icon."
                >
                    <Card>
                        <CardContent>
                            <Row label="Status">
                                {statuses.map((item) => (
                                    <Status key={item.label} {...item} />
                                ))}
                            </Row>
                            <Row label="Status pill">
                                {statuses.map((item) => (
                                    <StatusPill
                                        key={item.label}
                                        state={item.state}
                                        label={item.label}
                                    />
                                ))}
                            </Row>
                        </CardContent>
                    </Card>
                </Section>

                <Section
                    title="Logos"
                    description="Monochrome marks on neutral tiles; unknown kinds get a monogram."
                >
                    <Card>
                        <CardContent>
                            {([20, 32, 44] as LogoSize[]).map((size) => (
                                <Row key={size} label={`${size} px`}>
                                    {logos.map((kind) => (
                                        <Logo
                                            key={kind}
                                            kind={kind}
                                            size={size}
                                            name={
                                                kind === 'unknown-kind'
                                                    ? 'Billing API'
                                                    : undefined
                                            }
                                        />
                                    ))}
                                </Row>
                            ))}
                        </CardContent>
                    </Card>
                </Section>

                <Section title="Cards">
                    <div className="grid gap-4 md:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle>Card title</CardTitle>
                                <CardDescription>
                                    One sentence of supporting text.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="type-body text-muted-foreground">
                                Cards hold objects you own. Inside a card, use
                                recessed areas, not nested cards.
                            </CardContent>
                        </Card>
                        <Card className="gap-0 overflow-hidden py-0">
                            <div className="flex h-28 items-center justify-center bg-recessed">
                                <Logo kind="nextjs" size={44} />
                            </div>
                            <div className="flex flex-col gap-1 border-t p-5">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="type-heading">web</span>
                                    <Status state="active" label="Live" />
                                </div>
                                <span className="type-small text-muted-foreground">
                                    Media stage, then header
                                </span>
                            </div>
                        </Card>
                        <Card>
                            <CardContent className="flex flex-col gap-1">
                                <span className="type-label text-muted-foreground">
                                    CPU
                                </span>
                                <span className="type-stat">34%</span>
                                <span className="type-small text-muted-foreground">
                                    avg 29% over the last hour
                                </span>
                            </CardContent>
                        </Card>
                    </div>
                </Section>

                <Section title="Empty states">
                    <div className="grid gap-4 md:grid-cols-2">
                        <EmptyState
                            icon={Rocket}
                            action={<Button>Deploy</Button>}
                        >
                            No deployments yet. Push to main or press Deploy.
                        </EmptyState>
                        <EmptyState
                            icon={GitPullRequest}
                            secondary={
                                <Button variant="link">Preview settings</Button>
                            }
                        >
                            Open a pull request to get a preview.
                        </EmptyState>
                    </div>
                </Section>

                <Section
                    title="Choices"
                    description="Choose-your-X tiles and mode cards."
                >
                    <ChoiceTiles
                        label="Machine"
                        value={machine}
                        onChange={setMachine}
                        columns={4}
                        options={[
                            {
                                value: 'cloud',
                                label: 'Cloud server',
                                description: 'Hetzner, DigitalOcean…',
                                media: (
                                    <Logo kind="cloud" size={44} decorative />
                                ),
                            },
                            {
                                value: 'minipc',
                                label: 'Mini PC',
                                description: 'NUC, Mac mini',
                                media: (
                                    <Logo kind="server" size={44} decorative />
                                ),
                            },
                            {
                                value: 'sbc',
                                label: 'Raspberry Pi',
                                description: 'arm64 boards',
                                media: (
                                    <Logo
                                        kind="raspberrypi"
                                        size={44}
                                        decorative
                                    />
                                ),
                            },
                            {
                                value: 'nas',
                                label: 'NAS',
                                description: 'Synology, QNAP',
                                media: <Logo kind="s3" size={44} decorative />,
                                disabled: true,
                                badge: 'Coming soon',
                            },
                        ]}
                        other={{
                            label: 'Something else',
                            description: 'Any Linux machine with Docker',
                            media: <Logo kind="ubuntu" size={32} decorative />,
                            onSelect: () => toast.info('Other selected'),
                        }}
                    />
                    <OptionCards
                        label="Registry"
                        value={mode}
                        onChange={setMode}
                        options={[
                            {
                                value: 'builtin',
                                title: 'Built-in',
                                description:
                                    'Images stay on your servers. Nothing to set up.',
                            },
                            {
                                value: 'own',
                                title: 'Your own registry',
                                description:
                                    'Push to GHCR or any registry you already use.',
                            },
                        ]}
                    />
                </Section>

                <Section title="Guided setup">
                    <div className="grid gap-6 lg:grid-cols-2">
                        <Wizard
                            steps={[
                                {
                                    id: 'what',
                                    title: 'What is it',
                                    summary: 'Mini PC at home',
                                },
                                {
                                    id: 'does',
                                    title: 'What it does',
                                    summary: 'Runtime and build',
                                },
                                { id: 'connect', title: 'Connect' },
                            ]}
                            current={step}
                            onStepSelect={setStep}
                            footer={
                                <>
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            setStep(Math.max(0, step - 1))
                                        }
                                    >
                                        Back
                                    </Button>
                                    <Button
                                        onClick={() =>
                                            setStep(Math.min(2, step + 1))
                                        }
                                    >
                                        Continue
                                    </Button>
                                </>
                            }
                        >
                            <Stepper
                                label="Replicas"
                                value={replicas}
                                onChange={setReplicas}
                                min={1}
                                max={6}
                                consequence={`fits on ${Math.min(replicas, 3)} servers`}
                                helper="Replicas spread over servers with the runtime role."
                            />
                        </Wizard>
                        <LiveWait
                            media={<Logo kind="server" size={44} decorative />}
                            status="Waiting for studio to connect"
                            hint="Usually under a minute"
                            timeoutSeconds={3}
                            fallback={
                                <Button variant="link">Not working?</Button>
                            }
                        />
                    </div>
                </Section>

                <Section title="Confirm, bars, toasts">
                    <Card>
                        <CardContent>
                            <Row label="Confirm popover">
                                <ConfirmPopover
                                    title="Roll back to 3b7d5e1?"
                                    consequence="web serves the previous version again; the current one stays in history."
                                    confirmLabel="Roll back"
                                    onConfirm={() => {
                                        toast.success('Rolled back');
                                    }}
                                >
                                    <Button variant="outline">Roll back</Button>
                                </ConfirmPopover>
                                <ConfirmPopover
                                    consequence="mara loses access to this team's projects and servers."
                                    confirmLabel="Remove"
                                    tone="destructive"
                                    onConfirm={() => undefined}
                                >
                                    <Button variant="ghost">
                                        Remove member
                                    </Button>
                                </ConfirmPopover>
                            </Row>
                            <Row label="Unsaved bar">
                                <Switch
                                    checked={dirty}
                                    onCheckedChange={setDirty}
                                    aria-label="Show unsaved bar"
                                />
                                <span className="type-small text-muted-foreground">
                                    Floats at the bottom of the page.
                                </span>
                            </Row>
                            <Row label="Toasts">
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        toast.success(
                                            'web is live after 1m 12s',
                                            {
                                                action: {
                                                    label: 'Open',
                                                    onClick: () => undefined,
                                                },
                                            },
                                        )
                                    }
                                >
                                    Success
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        toast.error(
                                            'Build failed: pnpm lockfile is out of date',
                                            {
                                                action: {
                                                    label: 'View',
                                                    onClick: () => undefined,
                                                },
                                            },
                                        )
                                    }
                                >
                                    Error
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        toast('Variable removed', {
                                            action: {
                                                label: 'Undo',
                                                onClick: () => undefined,
                                            },
                                        })
                                    }
                                >
                                    Undo
                                </Button>
                            </Row>
                            <div className="relative mt-2 h-24 rounded-lg bg-recessed">
                                <StagedBar
                                    placement="inline"
                                    count={2}
                                    summary="api will restart"
                                    onDiscard={() => undefined}
                                    onDeploy={() => undefined}
                                />
                            </div>
                        </CardContent>
                    </Card>
                </Section>

                {Object.entries(extensions).map(([path, module]) => (
                    <Section
                        key={path}
                        title={
                            module.title ??
                            path.replace('../../components/', '')
                        }
                    >
                        <module.default />
                    </Section>
                ))}
            </Page>

            <UnsavedBar
                open={dirty}
                onReset={() => setDirty(false)}
                onSave={() => setDirty(false)}
            />

            <SidePanel
                open={sheet}
                onOpenChange={setSheet}
                size="md"
                media={<Logo kind="nextjs" size={32} decorative />}
                title="web"
                status={<StatusPill state="active" label="Live" />}
                description="Next.js 16 from acme/shop"
            >
                <SheetSection
                    title="Public networking"
                    description="Reachable from the internet."
                    control={
                        <Switch defaultChecked aria-label="Public networking" />
                    }
                >
                    <p className="type-mono text-muted-foreground">
                        shop.acme.com
                    </p>
                </SheetSection>
                <SheetSection
                    title="Source"
                    description="Deploys on every push to main."
                >
                    <div className="flex items-center gap-2 type-body">
                        <FolderGit2
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                        acme/shop
                    </div>
                </SheetSection>
                <SheetSection title="Danger zone">
                    <div>
                        <Button variant="destructive" size="sm">
                            Remove service
                        </Button>
                    </div>
                </SheetSection>
                <UnsavedBar
                    placement="inline"
                    open
                    onReset={() => undefined}
                    onSave={() => undefined}
                />
            </SidePanel>
        </>
    );
}

DesignSheet.layout = {
    breadcrumbs: [{ title: 'Design primitives', href: '/dev/design' }],
};
