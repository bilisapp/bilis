<?php

namespace App\Mcp\Prompts;

use App\Content\InstrumentationPrompt;
use App\Http\Controllers\DocsController;
use App\Services\Docs\DocsPage;
use App\Services\Docs\DocsRepository;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('instrument-with-bilis')]
#[Title('Instrument with Bilis')]
#[Description('A paste-ready prompt for wiring a codebase up to send its logs and traces to Bilis. Pass a guide — otlp endpoints, a language, or a tool — and it points at that guide\'s current text rather than repeating it. It does not carry an API key: this server cannot mint one, and the prompt says where to get it.')]
class InstrumentWithBilisPrompt extends Prompt
{
    /**
     * The guide used when the caller does not name one.
     */
    private const DEFAULT_GUIDE = 'endpoints';

    /**
     * Handle the prompt request.
     */
    public function handle(Request $request, DocsRepository $docs): Response
    {
        $guide = $request->get('guide');
        $slug = is_string($guide) && $guide !== '' ? $guide : self::DEFAULT_GUIDE;

        $page = $docs->find('ingestion', $slug) ?? $docs->find('ingestion', self::DEFAULT_GUIDE);

        if (! $page instanceof DocsPage) {
            return Response::error('The ingestion guides are unavailable on this Bilis instance.');
        }

        $prompt = InstrumentationPrompt::forPage(
            $page,
            rtrim(url('/'), '/'),
            DocsController::API_KEY_PLACEHOLDER,
        );

        /*
         * The MCP server is read-only and has no key tool by design, so the
         * placeholder is left in place with an instruction rather than being
         * quietly filled in from somewhere it should not have been read.
         */
        return Response::text($prompt."\n\n---\n\nThe API key above is a placeholder. Ask the person you are working with for a project key from the Bilis web app (Projects → the project → API keys); it is shown once, starts with `bilis_`, and this MCP server can neither create nor read one.");
    }

    /**
     * Get the prompt's arguments.
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'guide',
                description: 'Which ingestion guide to follow: endpoints (the raw OTLP and JSON contract), go, linux-host, claude-code, sentry, shippers, traces, api-keys, severity or timestamps. Defaults to endpoints.',
                required: false,
            ),
        ];
    }
}
