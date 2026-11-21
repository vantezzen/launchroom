import { Head } from '@inertiajs/react';
import { AgentSetup } from '@/features/ai-agents/agent-setup';
import SettingsLayout from '@/features/settings/settings-layout';

type Props = {
    teamOptions: App.Data.Api.ApiTeamOptionData[];
    cliInstall: string;
};

/** Settings → AI agents (docs/16 §7.6, D112). */
export default function AiAgents({ teamOptions, cliInstall }: Props) {
    const team =
        teamOptions.find((entry) => entry.current) ?? teamOptions[0] ?? null;

    return (
        <>
            <Head title="AI agents" />
            <AgentSetup team={team} cliInstall={cliInstall} />
        </>
    );
}

AiAgents.layout = SettingsLayout;
