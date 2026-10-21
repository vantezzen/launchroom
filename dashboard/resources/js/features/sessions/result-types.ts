/** A data command's result (reassembled from `result` frames, docs/03 §11.2). */
export type DataResult = {
    op: string;
    status: 'ok' | 'error' | 'refused';
    truncated?: boolean;
    cellsTruncated?: boolean;
    durationMs: number;
    columns?: string[];
    rows?: (string | null)[][];
    affected?: number | null;
    message?: string;
    schema?: {
        engine: string;
        groups: {
            name: string;
            rows?: number;
            items: { name: string; kind: string; rows?: number }[];
        }[];
    };
    keys?: {
        cursor: string;
        items: { key: string; type: string; ttlMs: number }[];
    };
    value?: {
        key: string;
        type: string;
        ttlMs: number;
        size: number;
        truncated: boolean;
        text?: string;
        items?: string[];
        entries?: string[][];
    };
    reply?: unknown;
};
