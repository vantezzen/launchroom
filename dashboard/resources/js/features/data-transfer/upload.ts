import { chunks, complete, show } from '@/routes/transfers';
import { sha256 as pureSha256 } from '@/features/sessions/hmac';
import { messageFrom, TransferRequestFailed, transfersApi } from './api';
import type { Transfer } from './api';

export type UploadProgress = {
    sentBytes: number;
    totalBytes: number;
    /** Measured over the last chunks; null before there is anything to measure. */
    bytesPerSecond: number | null;
    secondsLeft: number | null;
};

type UploadOptions = {
    serviceInstanceId: string;
    createUrl: string;
    purpose: 'database_import' | 'volume_import';
    volume?: string | null;
    file: File;
    onProgress: (progress: UploadProgress) => void;
    signal: AbortSignal;
};

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

function resumeKey(o: UploadOptions): string {
    return [
        'launchroom:upload',
        o.serviceInstanceId,
        o.purpose,
        o.volume ?? '',
        o.file.name,
        o.file.size,
        o.file.lastModified,
    ].join(':');
}

function remembered(key: string): string | null {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function remember(key: string, id: string | null): void {
    try {
        if (id === null) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, id);
        }
    } catch {
        // Private mode: no resume after a reload, the upload still works.
    }
}

async function sha256(buffer: ArrayBuffer): Promise<string> {
    // WebCrypto exists only in secure contexts; a dashboard reached over
    // plain HTTP on a LAN address hashes with the pure implementation.
    const digest = window.crypto?.subtle
        ? await window.crypto.subtle.digest('SHA-256', buffer)
        : pureSha256(new Uint8Array(buffer));

    return Array.from(new Uint8Array(digest), (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}

async function putChunk(
    transfer: Transfer,
    index: number,
    body: ArrayBuffer,
    digest: string,
    signal: AbortSignal,
): Promise<void> {
    const token = xsrfToken();
    const response = await fetch(chunks({ transfer: transfer.id, index }).url, {
        method: 'PUT',
        body,
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/octet-stream',
            'X-Chunk-Sha256': digest,
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
    });

    if (!response.ok) {
        throw new TransferRequestFailed(
            messageFrom(await response.text(), response.status),
            response.status,
        );
    }
}

/**
 * Resumable, chunked upload (docs/14 §12.1): the transfer id is
 * remembered per file, so a reload continues with the chunks that are
 * still missing. Each chunk carries its SHA-256 and is retried a few
 * times; the server checks the whole file when it is complete.
 */
export async function uploadFile(o: UploadOptions): Promise<Transfer> {
    const key = resumeKey(o);
    let transfer: Transfer | null = null;
    const resumeId = remembered(key);

    if (resumeId !== null) {
        try {
            const existing = await transfersApi<Transfer>(
                'get',
                show(resumeId).url,
                undefined,
                o.signal,
            );

            if (
                existing.sizeBytes === o.file.size &&
                (existing.status === 'receiving' || existing.status === 'ready')
            ) {
                transfer = existing;
            }
        } catch {
            // Gone: start over.
        }
    }

    if (transfer === null) {
        transfer = await transfersApi<Transfer>(
            'post',
            o.createUrl,
            {
                purpose: o.purpose,
                fileName: o.file.name,
                sizeBytes: o.file.size,
                volume: o.volume ?? null,
            },
            o.signal,
        );
        remember(key, transfer.id);
    }

    if (transfer.status === 'ready') {
        o.onProgress({
            sentBytes: o.file.size,
            totalBytes: o.file.size,
            bytesPerSecond: null,
            secondsLeft: 0,
        });

        return transfer;
    }

    const received = new Set(transfer.receivedChunks);
    const chunkBytes = transfer.chunkBytes;
    let sent = 0;

    for (const index of received) {
        sent += Math.min(chunkBytes, o.file.size - index * chunkBytes);
    }

    const samples: { at: number; bytes: number }[] = [
        { at: performance.now(), bytes: sent },
    ];
    const report = () => {
        const first = samples[0];
        const last = samples[samples.length - 1];
        const seconds = (last.at - first.at) / 1000;
        const rate =
            seconds > 0.5 ? (last.bytes - first.bytes) / seconds : null;

        o.onProgress({
            sentBytes: sent,
            totalBytes: o.file.size,
            bytesPerSecond: rate,
            secondsLeft:
                rate !== null && rate > 0
                    ? Math.round((o.file.size - sent) / rate)
                    : null,
        });
    };

    report();

    for (let index = 0; index < transfer.chunks; index++) {
        if (received.has(index)) {
            continue;
        }

        const blob = o.file.slice(
            index * chunkBytes,
            Math.min(o.file.size, (index + 1) * chunkBytes),
        );
        const body = await blob.arrayBuffer();
        const digest = await sha256(body);

        for (let attempt = 1; ; attempt++) {
            try {
                await putChunk(transfer, index, body, digest, o.signal);
                break;
            } catch (error) {
                const status =
                    error instanceof TransferRequestFailed ? error.status : 0;
                const retryable =
                    status === 0 || status === 429 || status >= 500;

                if (o.signal.aborted || !retryable || attempt >= 5) {
                    throw error;
                }

                await new Promise((resolve) =>
                    window.setTimeout(resolve, 1000 * 2 ** (attempt - 1)),
                );
            }
        }

        sent += body.byteLength;
        samples.push({ at: performance.now(), bytes: sent });

        if (samples.length > 8) {
            samples.shift();
        }

        report();
    }

    try {
        const done = await transfersApi<Transfer>(
            'post',
            complete(transfer.id).url,
            undefined,
            o.signal,
        );
        remember(key, done.id);

        return done;
    } catch (error) {
        // A refused file is deleted: don't try to resume it.
        if (error instanceof TransferRequestFailed && error.status === 422) {
            remember(key, null);
        }

        throw error;
    }
}
