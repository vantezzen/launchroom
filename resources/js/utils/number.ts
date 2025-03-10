export function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}

/**
 * Format bytes to a human-readable string
 */
export function formatBytes(bytes: number, decimals = 2): string {
    if (bytes === 0) return '0 Bytes';

    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];

    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return `${Number.parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
}

/**
 * Format percentage to a human-readable string
 */
export function formatPercentage(value: number, decimals = 1): string {
    return `${value.toFixed(decimals)}%`;
}
