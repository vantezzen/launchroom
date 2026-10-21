import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';

const states: Record<App.Enums.Mesh.MeshMemberState, StatusState> = {
    off: 'idle',
    connecting: 'progress',
    connected: 'active',
    error: 'failed',
};

const labels: Record<App.Enums.Mesh.MeshMemberState, string> = {
    off: 'Not connected',
    connecting: 'Connecting',
    connected: 'Connected',
    error: 'Error',
};

export const pathLabels: Record<App.Enums.Mesh.MeshPath, string> = {
    direct: 'Direct',
    relay: 'Relayed',
    idle: 'Idle',
};

export const pathDescriptions: Record<App.Enums.Mesh.MeshPath, string> = {
    direct: 'Traffic flows directly between the servers.',
    relay: 'Traffic is relayed through the dashboard’s server (works behind any NAT, slower).',
    idle: 'No traffic recently; the path is chosen on first use.',
};

/**
 * A server's private network state (docs/05 §2), with how it reaches the
 * dashboard's server when known.
 */
export function MeshStatus({
    mesh,
}: {
    mesh: App.Data.Servers.ServerMeshData | null;
}) {
    if (mesh === null) {
        return <Status state="idle" label="Not connected" />;
    }

    const label =
        mesh.state === 'connected' && mesh.pathToControlPlane !== null
            ? `${labels.connected} · ${pathLabels[mesh.pathToControlPlane].toLowerCase()}`
            : labels[mesh.state];

    return <Status state={states[mesh.state]} label={label} />;
}

export const meshModeLabels: Record<App.Enums.Mesh.MeshMode, string> = {
    embedded: 'Built-in',
    byo: 'Your Tailscale network',
};
