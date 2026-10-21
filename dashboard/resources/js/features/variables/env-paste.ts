const ENV_LINE = /^\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=/;

/**
 * Whether pasted text looks like a `.env` file (docs/06 §3.4 "paste a .env
 * anywhere"): at least one `KEY=value` line, and more than one line or an
 * assignment. Parsing is the server's job (EnvFileParser).
 */
export function looksLikeEnv(text: string): boolean {
    const lines = text
        .split(/\r?\n/)
        .filter((line) => line.trim() !== '' && !line.trim().startsWith('#'));

    return (
        lines.some((line) => ENV_LINE.test(line)) &&
        (lines.length > 1 || ENV_LINE.test(lines[0]))
    );
}
