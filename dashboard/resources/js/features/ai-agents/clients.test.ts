import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import { AGENT_ABILITIES, AGENT_CLIENTS, loginCommand } from './clients';

describe('AI agent setup (docs/16 §7.6)', () => {
    it('gives every editor a command and a snippet that starts launchroom mcp, never a token', () => {
        for (const client of AGENT_CLIENTS) {
            expect(client.command).toContain('launchroom');
            expect(client.snippet).toContain('launchroom');
            expect(client.snippet).toContain('mcp');
            expect(client.snippet.toLowerCase()).not.toContain('token');

            if (!client.file.endsWith('.toml')) {
                expect(() => JSON.parse(client.snippet)).not.toThrow();
            }
        }
    });

    it('asks for the agent access and never secrets or data exports', () => {
        expect(AGENT_ABILITIES).not.toContain('secrets:read');
        expect(AGENT_ABILITIES).not.toContain('data:transfer');
        expect(loginCommand(['read', 'deploy'])).toBe(
            'launchroom login --abilities read,deploy',
        );
    });
});

type FixtureClient = {
    id: string;
    name: string;
    project: string;
    global: Record<string, string>;
    format: 'mcpServers' | 'vscode' | 'codex-toml';
    command: string;
    snippet: string;
};

// `launchroom mcp install`'s table (cli/internal/mcpclients), written by `make gen-cli`.
const fixture = JSON.parse(
    readFileSync(
        new URL(
            '../../../../../protocol/fixtures/cli/mcp-clients.json',
            import.meta.url,
        ),
        'utf8',
    ),
) as { clients: FixtureClient[] };

/** The file the page names: the project's, else the home one (or its name when it differs per OS). */
function shownFile(client: FixtureClient): string {
    if (client.project !== '') {
        return client.project;
    }

    const paths = [...new Set(Object.values(client.global))];

    return paths.length === 1
        ? `~/${paths[0]}`
        : (paths[0].split('/').at(-1) ?? '');
}

describe('the page matches launchroom mcp install', () => {
    it('lists the same editors in the same order', () => {
        expect(AGENT_CLIENTS.map((c) => [c.id, c.name])).toEqual(
            fixture.clients.map((c) => [c.id, c.name]),
        );
    });

    it.each(fixture.clients)('$id: file, command and entry', (expected) => {
        const client = AGENT_CLIENTS.find((c) => c.id === expected.id);

        expect(client?.file).toBe(shownFile(expected));
        expect(client?.command).toBe(
            expected.command !== ''
                ? expected.command
                : `launchroom mcp install --client ${expected.id} --write`,
        );

        if (expected.format === 'codex-toml') {
            expect(client?.snippet.trimEnd()).toBe(expected.snippet.trimEnd());
        } else {
            expect(JSON.parse(client?.snippet ?? '')).toEqual(
                JSON.parse(expected.snippet),
            );
        }
    });
});
