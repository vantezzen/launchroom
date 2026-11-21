import { Bot } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/patterns/empty-state';
import {
    SettingsBody,
    SettingsCard,
} from '@/components/settings/settings-card';
import { Button } from '@/components/ui/button';
import {
    CreateRobotForm,
    RobotTokenDialog,
    RobotTokenSecret,
} from '@/features/robot-tokens/create-robot-dialog';
import { RobotTokenRow } from '@/features/robot-tokens/robot-token-row';

type Token = App.Data.Api.RobotTokenData;

const DAY = 24 * 60 * 60 * 1000;
const at = (days: number) => new Date(Date.now() + days * DAY).toISOString();

const ABILITIES: App.Data.Api.ApiAbilityOptionData[] = (
    [
        ['read', 'Read', true],
        ['deploy', 'Deploy', true],
        ['env:read', 'Read variables', true],
        ['env:write', 'Change variables', true],
        ['secrets:read', 'Read secret values', false],
        ['domains:write', 'Manage domains', false],
        ['data:transfer', 'Import and export data', false],
        ['run', 'Run commands', false],
        ['fix', 'Apply suggested fixes', true],
        ['update', 'Update apps', false],
    ] as const
).map(([value, label, preset]) => ({
    value,
    label,
    description: `${label}.`,
    available: true,
    default: preset,
    locked: value === 'read',
}));

const TEAM: App.Data.Api.ApiTeamOptionData = {
    id: '',
    name: 'Acme',
    role: 'admin',
    current: true,
    abilities: ABILITIES,
};

const SCOPES: App.Data.Api.RobotScopeOptionData[] = [
    {
        id: 'prj_01',
        name: 'shop',
        services: [
            { id: 'svc_01', name: 'api' },
            { id: 'svc_02', name: 'web' },
        ],
    },
    { id: 'prj_02', name: 'blog', services: [] },
];

function token(overrides: Partial<Token>): Token {
    return {
        id: 'tok_01',
        name: 'GitHub Actions',
        hint: 'lr_pat_…a1b2',
        abilities: ABILITIES.filter((option) => option.default).map(
            (option) => option.value,
        ),
        fullAccess: false,
        projectId: null,
        projectName: null,
        serviceId: null,
        serviceName: null,
        createdBy: 'Ana Lima',
        expiresAt: at(80),
        lastUsedAt: new Date(Date.now() - 3 * 60 * 1000).toISOString(),
        lastUsedIp: '203.0.113.7',
        createdAt: at(-10),
        ...overrides,
    };
}

const TOKENS: Token[] = [
    token({}),
    token({
        id: 'tok_04',
        name: 'Release bot',
        hint: 'lr_pat_…5e21',
        abilities: ABILITIES.map((option) => option.value),
        fullAccess: true,
    }),
    token({
        id: 'tok_02',
        name: 'Deploy api',
        hint: 'lr_pat_…9f3c',
        abilities: ['read', 'deploy'],
        projectId: 'prj_01',
        projectName: 'shop',
        serviceId: 'svc_01',
        serviceName: 'api',
        expiresAt: at(5),
    }),
    token({
        id: 'tok_03',
        name: 'Nightly migrations',
        hint: 'lr_pat_…07de',
        abilities: ['read', 'run'],
        projectId: 'prj_01',
        projectName: 'shop',
        createdBy: null,
        lastUsedAt: null,
        lastUsedIp: null,
    }),
];

const noop = () => Promise.resolve();

function Frame({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <p className="type-small text-muted-foreground">{label}</p>
            <div className="max-w-[760px]">{children}</div>
        </div>
    );
}

function List({ tokens, canManage }: { tokens: Token[]; canManage: boolean }) {
    return (
        <SettingsCard
            title="Tokens"
            description="For CI and automation. They keep working when someone leaves the team."
            actions={
                canManage && (
                    <Button variant="outline" size="sm">
                        Create robot token
                    </Button>
                )
            }
        >
            {tokens.length === 0 ? (
                <SettingsBody>
                    <EmptyState icon={Bot} variant="plain">
                        {canManage
                            ? 'No robot tokens yet. Create one for a deploy job, so it doesn’t depend on one person’s token.'
                            : 'No robot tokens yet. Owners and admins create them.'}
                    </EmptyState>
                </SettingsBody>
            ) : (
                tokens.map((entry) => (
                    <RobotTokenRow
                        key={entry.id}
                        token={entry}
                        canManage={canManage}
                        onRotate={noop}
                        onRevoke={noop}
                    />
                ))
            )}
        </SettingsCard>
    );
}

/**
 * Settings → Robot tokens (docs/16 §1.4) in every state: all projects, one
 * service expiring soon, one project never used with its creator gone, a
 * member's read-only view, empty, the create dialog and the show-once view.
 */
export default function RobotTokenStates() {
    const [open, setOpen] = useState<'create' | 'secret' | 'rotated' | null>(
        null,
    );

    return (
        <div className="grid gap-10">
            <Frame label="Owners and admins: all projects, one service expiring soon, one project never used">
                <List tokens={TOKENS} canManage />
            </Frame>
            <Frame label="A developer or viewer sees them without actions">
                <List tokens={TOKENS.slice(0, 1)} canManage={false} />
            </Frame>
            <Frame label="None yet">
                <List tokens={[]} canManage />
            </Frame>
            <Frame label="Dialogs">
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" onClick={() => setOpen('create')}>
                        Create robot token
                    </Button>
                    <Button variant="outline" onClick={() => setOpen('secret')}>
                        Show once
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => setOpen('rotated')}
                    >
                        After Rotate
                    </Button>
                </div>
            </Frame>
            <RobotTokenDialog
                open={open !== null}
                onOpenChange={(next) => !next && setOpen(null)}
            >
                {open === 'create' && (
                    <CreateRobotForm
                        team={TEAM}
                        scopes={SCOPES}
                        lifetimes={[7, 30, 90, 365]}
                        defaultLifetime={90}
                        onSubmit={noop}
                    />
                )}
                {(open === 'secret' || open === 'rotated') && (
                    <RobotTokenSecret
                        created={{
                            token: TOKENS[0],
                            plainText:
                                '42|lr_pat_Q8rT2mZ4xVb7nK1pLs9dWc3yHf6gJe0uA5oN8iRk2a1b2',
                        }}
                        rotated={open === 'rotated'}
                        apiUrl="https://launchroom.example.com/api/v1"
                    />
                )}
            </RobotTokenDialog>
        </div>
    );
}
