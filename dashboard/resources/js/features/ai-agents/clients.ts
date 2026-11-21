/**
 * Where each AI coding tool keeps its MCP servers and launchroom's entry
 * for it (docs/16 §7.6). The CLI's `launchroom mcp install` writes the same
 * entries (cli/internal/mcpclients); clients.test.ts holds this table to
 * its generated copy, protocol/fixtures/cli/mcp-clients.json. No entry holds a
 * token: `launchroom mcp` uses the CLI's own sign-in.
 */
export type AgentClient = {
    id: string;
    name: string;
    /** The one command that adds launchroom, run in the project. */
    command: string;
    /** The file the entry goes into. */
    file: string;
    snippet: string;
    /** A sentence for tools that need more than the snippet. */
    note?: string;
};

const ENTRY = { command: 'launchroom', args: ['mcp'] };

/** The file's shape with the entry on one line, as people write it. */
function wrap(key: string, entry: object): string {
    const line = JSON.stringify(entry)
        .replaceAll(',', ', ')
        .replaceAll(':', ': ');

    return `{\n  "${key}": {\n    "launchroom": ${line.replace(/^\{/, '{ ').replace(/\}$/, ' }')}\n  }\n}`;
}

function mcpServers(entry: object = ENTRY): string {
    return wrap('mcpServers', entry);
}

export const AGENT_CLIENTS: AgentClient[] = [
    {
        id: 'claude-code',
        name: 'Claude Code',
        command: 'claude mcp add launchroom -- launchroom mcp',
        file: '.mcp.json',
        snippet: mcpServers(),
    },
    {
        id: 'cursor',
        name: 'Cursor',
        command: 'launchroom mcp install --client cursor --write',
        file: '.cursor/mcp.json',
        snippet: mcpServers(),
    },
    {
        id: 'vscode',
        name: 'VS Code',
        command: 'launchroom mcp install --client vscode --write',
        file: '.vscode/mcp.json',
        snippet: wrap('servers', { type: 'stdio', ...ENTRY }),
    },
    {
        id: 'codex',
        name: 'Codex',
        command: 'codex mcp add launchroom -- launchroom mcp',
        file: '~/.codex/config.toml',
        snippet:
            '[mcp_servers.launchroom]\ncommand = "launchroom"\nargs = ["mcp"]',
    },
    {
        id: 'windsurf',
        name: 'Windsurf',
        command: 'launchroom mcp install --client windsurf --write',
        file: '~/.codeium/windsurf/mcp_config.json',
        snippet: mcpServers(),
    },
    {
        id: 'gemini',
        name: 'Gemini CLI',
        command: 'launchroom mcp install --client gemini --write',
        file: '.gemini/settings.json',
        snippet: mcpServers(),
    },
    {
        id: 'claude-desktop',
        name: 'Claude Desktop',
        command: 'launchroom mcp install --client claude-desktop --write',
        file: 'claude_desktop_config.json',
        snippet: mcpServers({
            command: 'launchroom',
            args: ['mcp', '--dir', '/path/to/your/project'],
        }),
        note: 'Claude Desktop starts servers outside your project, so the entry names it with --dir. The install command fills in the folder you run it in.',
    },
];

/**
 * The access an agent gets (docs/16 §7.6): everything it needs to ship and
 * fix, never secret values or data exports. `run` can be left out.
 */
export const AGENT_ABILITIES: App.Enums.ApiAbility[] = [
    'read',
    'deploy',
    'fix',
    'env:read',
    'env:write',
    'run',
];

/** `launchroom login` with the agent's abilities preselected. */
export function loginCommand(abilities: App.Enums.ApiAbility[]): string {
    return `launchroom login --abilities ${abilities.join(',')}`;
}
