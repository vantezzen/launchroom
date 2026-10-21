const units = ['B', 'KB', 'MB', 'GB', 'TB'];

/**
 * Compact byte sizes for tables ("1.4 MB"). Decimal units, like S3 consoles.
 */
export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }

    let value = bytes;
    let unit = 0;

    while (value >= 1000 && unit < units.length - 1) {
        value /= 1000;
        unit++;
    }

    return `${unit === 0 ? value : value.toFixed(value < 10 ? 1 : 0)} ${units[unit]}`;
}

export const reasonLabels: Record<App.Enums.Backups.BackupReason, string> = {
    scheduled: 'Scheduled',
    manual: 'Manual',
    pre_upgrade: 'Before upgrade',
    pre_restore: 'Before restore',
    pre_delete: 'Before delete',
    pre_deploy: 'Before deploy',
    pre_move: 'Before move',
};

export function reasonLabel(reason: string | null): string {
    return reason !== null && reason in reasonLabels
        ? reasonLabels[reason as App.Enums.Backups.BackupReason]
        : 'Backup';
}

export type StorageProvider = {
    /** Logo kind (components/logo). */
    logo: string;
    label: string;
};

/**
 * The storage provider behind an S3 endpoint, for the destination's logo
 * tile. Unknown hosts are "S3-compatible".
 */
export function storageProvider(endpoint: string): StorageProvider {
    const host = endpoint.toLowerCase();

    if (host.includes('r2.cloudflarestorage.com')) {
        return { logo: 'cloudflare', label: 'Cloudflare R2' };
    }

    if (host.includes('your-objectstorage.com')) {
        return { logo: 'hetzner', label: 'Hetzner Object Storage' };
    }

    if (host.includes('backblazeb2.com')) {
        return { logo: 'backblaze', label: 'Backblaze B2' };
    }

    if (host.includes('digitaloceanspaces.com')) {
        return { logo: 'digitalocean', label: 'DigitalOcean Spaces' };
    }

    if (host.includes('scw.cloud')) {
        return { logo: 'scaleway', label: 'Scaleway' };
    }

    if (host.includes('amazonaws.com')) {
        return { logo: 's3', label: 'Amazon S3' };
    }

    return { logo: 's3', label: 'S3-compatible' };
}
