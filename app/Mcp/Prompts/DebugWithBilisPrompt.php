<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('debug-with-bilis')]
#[Title('Debug with Bilis')]
#[Description('The route from a symptom to a cause using this server\'s tools: find the failing lines, group them into distinct problems, then open the failing request as a waterfall. Pass the symptom you were given and it becomes a plan.')]
class DebugWithBilisPrompt extends Prompt
{
    /**
     * Handle the prompt request.
     */
    public function handle(Request $request): Response
    {
        $symptom = $request->get('symptom');
        $opening = is_string($symptom) && $symptom !== ''
            ? "The reported symptom is: {$symptom}\n\n"
            : "Ask what the symptom is if you have not been told one.\n\n";

        return Response::text($opening.<<<'PROMPT'
            Work it out from the data rather than from the code, in this order:

            1. `list-projects` — unless you already know which project the symptom belongs to.
               Note whether it has traces as well as logs; the last step needs them.
            2. `error-summary` over the window the symptom happened in (pass `from` and `to`
               when it was not the last hour). This gives distinct problems with counts, not
               a scroll of duplicate lines. Say which group you are pursuing and why — usually
               the one whose first occurrence lines up with the symptom, not the loudest.
            3. `search-logs` with that group's service and a term from its message, to read
               the lines around it in order. What came immediately before the first failure
               is usually more informative than the failure.
            4. `get-trace` with the `traceId` from one of those lines, and the `spanId` too if
               you have it. The waterfall shows the whole request: which service failed first,
               what it was doing, how long each step took. A cause two services upstream is
               common; the log line is often only where it surfaced.
            5. If the symptom is slowness rather than an error, replace steps 2–3 with
               `service-latency` for the window, then `list-traces` with `min_duration_ms`
               near the p95 to open a representative slow request.

            Then say what broke, where, and what the evidence was — quoting the log line and
            the span. Propose a fix separately, and be explicit about which part is inference:
            you have read the symptoms, not the code path.

            If a result carries `"unavailable": true` the store did not answer. Retry with a
            narrower window and say so; do not report it as "no errors found".
            PROMPT);
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
                name: 'symptom',
                description: 'What was reported, in whatever words you were given: "checkout 500s since the deploy", "the app is slow this morning".',
                required: false,
            ),
        ];
    }
}
