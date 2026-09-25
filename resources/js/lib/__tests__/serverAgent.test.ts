import { describe, expect, it } from 'vitest';
import {
    API_KEY_PLACEHOLDER,
    serverInstallCommand,
    shellQuote,
} from '@/lib/serverAgent';

describe('serverInstallCommand', () => {
    it('fills in the key when it is known', () => {
        expect(serverInstallCommand('https://bilis.app', 'bilis_abc123')).toBe(
            'curl -fsSL https://bilis.app/install.sh | sudo BILIS_API_KEY=bilis_abc123 sh',
        );
    });

    it('falls back to the placeholder without a key', () => {
        const expected = `curl -fsSL https://bilis.app/install.sh | sudo BILIS_API_KEY=${API_KEY_PLACEHOLDER} sh`;

        expect(serverInstallCommand('https://bilis.app')).toBe(expected);
        expect(serverInstallCommand('https://bilis.app', null)).toBe(expected);
        expect(serverInstallCommand('https://bilis.app', '  ')).toBe(expected);
        expect(API_KEY_PLACEHOLDER).toBe('bilis_YOUR_API_KEY');
    });

    it('trims a trailing slash off the origin', () => {
        expect(serverInstallCommand('https://bilis.app/', 'bilis_k')).toBe(
            'curl -fsSL https://bilis.app/install.sh | sudo BILIS_API_KEY=bilis_k sh',
        );
        expect(serverInstallCommand('http://bilis.test//', 'bilis_k')).toBe(
            'curl -fsSL http://bilis.test/install.sh | sudo BILIS_API_KEY=bilis_k sh',
        );
    });

    it('single-quotes a key holding shell-unsafe characters', () => {
        expect(serverInstallCommand('https://bilis.app', 'bilis_a$b c')).toBe(
            "curl -fsSL https://bilis.app/install.sh | sudo BILIS_API_KEY='bilis_a$b c' sh",
        );
        expect(
            serverInstallCommand('https://bilis.app', "bilis_x';rm -rf /;'"),
        ).toBe(
            `curl -fsSL https://bilis.app/install.sh | sudo BILIS_API_KEY='bilis_x'\\'';rm -rf /;'\\''' sh`,
        );
    });
});

describe('shellQuote', () => {
    it('leaves safe words alone', () => {
        expect(shellQuote('bilis_ABC123')).toBe('bilis_ABC123');
        expect(shellQuote('https://bilis.app:8443/install.sh')).toBe(
            'https://bilis.app:8443/install.sh',
        );
    });

    it('quotes an empty string', () => {
        expect(shellQuote('')).toBe("''");
    });
});
