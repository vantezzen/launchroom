/**
 * The command as shown: the join token keeps its prefix and last four
 * characters, the dashboard's key is shortened. Copy always takes the
 * full command (docs/design/flows.md §10).
 */
export function maskCommand(command: string): string {
    return command
        .replace(
            /(LR_JOIN_TOKEN=)(lr_jt_)?(\S+)/,
            (_match, name: string, prefix: string | undefined, token: string) =>
                `${name}${prefix ?? ''}${'•'.repeat(8)}${token.slice(-4)}`,
        )
        .replace(
            /(LR_CP_KEY=)((?:[a-z0-9]+:)?)(\S+)/,
            (_match, name: string, scheme: string, key: string) =>
                key.length > 12
                    ? `${name}${scheme}${key.slice(0, 6)}…${key.slice(-4)}`
                    : `${name}${scheme}${key}`,
        );
}
