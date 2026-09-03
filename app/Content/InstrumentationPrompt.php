<?php

namespace App\Content;

use App\Services\Docs\DocsPage;

/**
 * The prompt that hands a Bilis setup to a coding agent.
 *
 * It points at the raw markdown of a docs page rather than restating the
 * guide: the page is already served as text at a stable URL, so the agent
 * reads the current version instead of a copy that quietly goes stale. Both
 * the "hand it to an agent" card on every docs page and the MCP server's
 * `instrument-with-bilis` prompt render through here, so the two cannot drift.
 *
 * Pure: no clock, no storage, no request.
 */
final class InstrumentationPrompt
{
    /**
     * Build the prompt for one guide.
     *
     * @param  string  $endpoint  the Bilis instance the app should send to
     * @param  string  $key  a real API key, or the placeholder the reader must replace
     */
    public static function forPage(DocsPage $page, string $endpoint, string $key): string
    {
        $endpoint = rtrim($endpoint, '/');

        return <<<PROMPT
            Set up Bilis — self-hosted log and trace storage — in this project, following its "{$page->title}" guide:

            {$page->markdownUrl()}

            Bilis endpoint: {$endpoint}
            API key: {$key}

            Read the guide first, then make the changes it describes. Keep the API key in the environment rather than in a file that gets committed, and finish by sending one record and confirming it arrives.
            PROMPT;
    }
}
