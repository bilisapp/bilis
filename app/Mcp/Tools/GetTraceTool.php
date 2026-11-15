<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesScope;
use App\Services\Autofix\TraceContextBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('get-trace')]
#[Title('Get a trace')]
#[Description('Read one whole request as a text waterfall: every span indented by depth with its service, operation, duration and status, plus the attributes that locate a bug — the route, the SQL, the RPC, the exception. Failed spans are marked. Give it the traceId from a log line or from list-traces. The "state" field says why a waterfall is absent when one is: expired (spans older than 30 days), missing (no such trace here), or unavailable (the store did not answer). A very large trace is cut to fit: the span you name with span_id and its ancestors, failed spans, root spans and the slowest spans are kept first, always with their parents, and the last line counts the omitted spans per service. "span_count" is the whole trace, "rendered_spans" what the waterfall shows, "omitted_spans" the difference; "read_capped" is true only when the trace was too large or too long to read whole from storage.')]
class GetTraceTool extends Tool
{
    use ResolvesScope;

    /**
     * How many spans the waterfall shows at most. Larger than Autofix's
     * budget: here the trace is the whole answer, not one part of a prompt.
     */
    public const MAX_SPANS = 250;

    /**
     * How many characters the waterfall may occupy (roughly 5k tokens).
     */
    public const MAX_CHARS = 20000;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, TraceContextBuilder $context): Response
    {
        $scope = $this->resolveScope($request);

        if ($scope instanceof Response) {
            return $scope;
        }

        $request->validate([
            'trace_id' => ['required', 'string', 'regex:/^[0-9a-fA-F]{32}$/'],
            'span_id' => ['sometimes', 'string', 'regex:/^[0-9a-fA-F]{16}$/'],
        ], [
            'trace_id.regex' => 'A trace id is 32 hexadecimal characters.',
            'span_id.regex' => 'A span id is 16 hexadecimal characters.',
        ]);

        /*
         * The same builder the Autofix agent is handed. It resolves the
         * summary, reads the spans inside the trace's own bounds, renders the
         * waterfall within its span and character caps, and turns every empty
         * outcome into a state rather than an exception.
         */
        return Response::json($context->build(
            $scope->projectIds,
            (string) $request->get('trace_id'),
            $this->argument($request, 'span_id') ?? '',
            self::MAX_SPANS,
            self::MAX_CHARS,
        ));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'trace_id' => $schema->string()
                ->description('The trace to read, 32 hexadecimal characters. Log rows and list-traces both return one.')
                ->required(),
            'span_id' => $schema->string()
                ->description('The span the question is about, 16 hex characters — usually the spanId of the log line you started from. It is marked in the waterfall and kept, with its ancestors and siblings, when the trace is too large to render whole — pass it to keep a span the omission line says was cut.'),
            'team' => $schema->string()->description('Team slug. Omit to use the current team.'),
            'project' => $schema->string()->description('Project slug. Omit to search every project in the team.'),
        ];
    }
}
