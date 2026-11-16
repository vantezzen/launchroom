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

/** "26 Oct 2026, 08:07:15 CET": a restore point with its zone. */
export function zonedTime(iso: string): string {
    return new Date(iso).toLocaleString([], {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZoneName: 'short',
    });
}

/** "Europe/Berlin (UTC+01:00)": the viewer's zone, which picked times are in. */
export function zoneLine(at: Date = new Date()): string {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const offset = -at.getTimezoneOffset();
    const sign = offset >= 0 ? '+' : '-';
    const abs = Math.abs(offset);
    const hh = String(Math.floor(abs / 60)).padStart(2, '0');
    const mm = String(abs % 60).padStart(2, '0');

    return `${zone} (UTC${sign}${hh}:${mm})`;
}
