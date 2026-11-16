import { describe, expect, test } from 'vite-plus/test';
import { maskCommand } from './mask-command';

const command =
    'curl -fsSL https://lr.example.com/install/server.sh | LR_JOIN_TOKEN=lr_jt_wYQrh4Dvdlnc6euBnuThkuRvYWj9WA8a9QygQHJ5WVg LR_CP_KEY=ed25519:yGLcmFhMeB8tbmbA48Sf6aNZddTO5SGRxdSecCHau5s= LR_CONTROL_PLANE_URL=https://lr.example.com sh';

describe('maskCommand', () => {
    test('hides the join token except its prefix and last four characters', () => {
        const masked = maskCommand(command);

        expect(masked).toContain('LR_JOIN_TOKEN=lr_jt_••••••••5WVg ');
        expect(masked).not.toContain('wYQrh4Dvdlnc6euBnuThkuRvYWj9WA8a9QygQHJ');
    });

    test('shortens the dashboard key and keeps the rest of the command', () => {
        const masked = maskCommand(command);

        expect(masked).toContain('LR_CP_KEY=ed25519:yGLcmF…u5s= ');
        expect(masked).toContain(
            'curl -fsSL https://lr.example.com/install/server.sh | ',
        );
        expect(masked).toContain(
            'LR_CONTROL_PLANE_URL=https://lr.example.com sh',
        );
    });

    test('leaves a command without a token alone', () => {
        expect(maskCommand('curl -fsSL https://x/install.sh | sh')).toBe(
            'curl -fsSL https://x/install.sh | sh',
        );
    });
});
