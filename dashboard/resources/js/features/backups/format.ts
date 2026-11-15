import { labelOf, vocabulary } from '@/lib/vocabulary';

export function reasonLabel(reason: string | null): string {
    return labelOf(vocabulary.Backups.BackupReason, reason) ?? 'Backup';
}

type StorageProvider = {
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
