import { ServiceBackups } from '@/features/backups/backups-tab';
import { SettingsPage } from './section';
import type { SettingsContext } from './settings-context';

/**
 * Settings → Backups (docs/08 §16, docs/14): databases and services with
 * volumes. The snapshots, restores, imports and downloads of the former
 * Backups tab, on a settings page beside Volumes.
 */
export function BackupsSection({ context }: { context: SettingsContext }) {
    const [project, environment, service] = context.route;

    return (
        <SettingsPage
            title="Backups"
            description={
                context.node.kind === 'database'
                    ? 'Snapshots of this database, restores and imports.'
                    : 'Snapshots of this service’s volumes, restores and imports.'
            }
        >
            <ServiceBackups
                project={project}
                environment={environment}
                service={service}
            />
        </SettingsPage>
    );
}

/** Backups exist for databases and services with a volume (docs/14 §2). */
export function hasBackups(node: App.Data.Canvas.CanvasNodeData): boolean {
    return node.kind === 'database' || node.volume !== null;
}
