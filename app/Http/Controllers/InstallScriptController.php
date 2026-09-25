<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use RuntimeException;

/**
 * Serves the Linux server agent installer: `curl -fsSL <origin>/install.sh | sudo sh`.
 *
 * The script is a static file with one placeholder, `__BILIS_ORIGIN__`, which
 * becomes this instance's canonical URL from config — never anything from the
 * request, so a spoofed Host header cannot point a server's agent at someone
 * else. What the script downloads is pinned by version and SHA-256 inside it
 * (`.ai/rules/install.md`).
 */
class InstallScriptController extends Controller
{
    /**
     * The installer, as plain text.
     */
    public function __invoke(): Response
    {
        $script = file_get_contents(resource_path('install/install.sh'));

        if ($script === false) {
            throw new RuntimeException('The agent installer is missing from resources/install/install.sh.');
        }

        $rendered = str_replace('__BILIS_ORIGIN__', rtrim((string) config('app.url'), '/'), $script);

        if (str_contains($rendered, '__BILIS_')) {
            throw new RuntimeException('The agent installer still carries a placeholder after rendering.');
        }

        return response($rendered, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
