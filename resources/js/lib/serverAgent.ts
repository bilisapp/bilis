/**
 * What the one-liner shows in place of a key when the plaintext is not known —
 * everywhere except the moment a key is created, since only its hash is stored.
 */
export const API_KEY_PLACEHOLDER = 'bilis_YOUR_API_KEY';

/** Characters a shell word may hold without quoting. */
const SHELL_SAFE = /^[A-Za-z0-9_@%+=:,./-]+$/;

/**
 * Quote a value for a POSIX shell. Keys are `bilis_` plus alphanumerics, so
 * this is a no-op in practice; anything else is single-quoted, with embedded
 * single quotes closed, escaped and reopened.
 */
export function shellQuote(value: string): string {
    if (SHELL_SAFE.test(value)) {
        return value;
    }

    return `'${value.replaceAll("'", `'\\''`)}'`;
}

/**
 * The command that installs the Linux server agent:
 * `curl -fsSL <origin>/install.sh | sudo BILIS_API_KEY=<key> sh`.
 */
export function serverInstallCommand(
    origin: string,
    apiKey?: string | null,
): string {
    const base = origin.trim().replace(/\/+$/, '');
    const key = apiKey?.trim() ? apiKey.trim() : API_KEY_PLACEHOLDER;

    return `curl -fsSL ${shellQuote(`${base}/install.sh`)} | sudo BILIS_API_KEY=${shellQuote(key)} sh`;
}
