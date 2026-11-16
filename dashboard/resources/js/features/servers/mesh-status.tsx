import { labels as wordsOf, vocabulary } from '@/lib/vocabulary';
import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';
import { meshMemberProblems } from '@/features/servers/problems';

const states: Record<App.Enums.Mesh.MeshMemberState, StatusState> = {
    off: 'idle',
    connecting: 'progress',
    connected: 'active',
    error: 'failed',
};

const labels: Record<App.Enums.Mesh.MeshMemberState, string> = {
    off: 'Not connected',
    connecting: meshMemberProblems.connecting.label,
    connected: 'Connected',
    error: meshMemberProblems.error.label,
};

export const pathLabels: Record<App.Enums.Mesh.MeshPath, string> = {
    direct: 'Direct',
    relay: 'Relayed',
    idle: 'Idle',
};

/**
 * A server's private network state (docs/05 §2), with how it reaches the
 * dashboard's server when known.
 */
export function MeshStatus({
    mesh,
    showPath = true,
}: {
    mesh: App.Data.Servers.ServerMeshData | null;
    /** False where the path is shown next to it already (Settings → Network). */
    showPath?: boolean;
}) {
    if (mesh === null) {
        return <Status state="idle" label="Not connected" />;
    }

    const path =
        showPath &&
        mesh.state === 'connected' &&
        mesh.pathToControlPlane !== null
            ? pathLabels[mesh.pathToControlPlane]
            : undefined;

    return (
        <Status
            state={states[mesh.state]}
            label={labels[mesh.state]}
            detail={path}
        />
    );
}

export const meshModeLabels = wordsOf(vocabulary.Mesh.MeshMode);
