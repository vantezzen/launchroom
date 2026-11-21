import type { ReactNode } from 'react';
import { AgentSetup } from '@/features/ai-agents/agent-setup';

const LABELS: [App.Enums.ApiAbility, string][] = [
    ['read', 'Read'],
    ['deploy', 'Deploy'],
    ['env:read', 'Read variables'],
    ['env:write', 'Change variables'],
    ['secrets:read', 'Read secret values'],
    ['domains:write', 'Manage domains'],
    ['data:transfer', 'Import and export data'],
    ['run', 'Run commands'],
    ['fix', 'Apply suggested fixes'],
];

function team(
    role: App.Enums.TeamRole,
    allowed: App.Enums.ApiAbility[],
): App.Data.Api.ApiTeamOptionData {
    return {
        id: 'team_01',
        name: 'Acme',
        role,
        current: true,
        abilities: LABELS.map(([value, label]) => ({
            value,
            label,
            description: `${label}.`,
            available: allowed.includes(value),
            default: [
                'read',
                'deploy',
                'env:read',
                'env:write',
                'fix',
            ].includes(value),
            locked: value === 'read',
        })),
    };
}

const INSTALL =
    'curl -fsSL https://github.com/vantezzen/launchroom/releases/latest/download/cli.sh | sh';

function Frame({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <p className="type-small text-muted-foreground">{label}</p>
            <div className="flex max-w-[760px] flex-col gap-6">{children}</div>
        </div>
    );
}

/**
 * Settings → AI agents (docs/16 §7.6): an owner (every permission, the
 * "Let it run commands" switch), a developer (no commands) and a viewer
 * (read only).
 */
export default function AiAgentStates() {
    return (
        <div className="grid gap-10">
            <Frame label="An owner or admin: the agent's access with the run switch">
                <AgentSetup
                    team={team(
                        'owner',
                        LABELS.map(([value]) => value),
                    )}
                    cliInstall={INSTALL}
                />
            </Frame>
            <Frame label="A developer: commands aren't available to the role">
                <AgentSetup
                    team={team('developer', [
                        'read',
                        'deploy',
                        'env:read',
                        'env:write',
                        'fix',
                    ])}
                    cliInstall={INSTALL}
                />
            </Frame>
            <Frame label="A viewer: read only">
                <AgentSetup
                    team={team('viewer', ['read'])}
                    cliInstall={INSTALL}
                />
            </Frame>
        </div>
    );
}
