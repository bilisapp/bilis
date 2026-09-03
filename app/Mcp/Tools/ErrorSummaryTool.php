<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesScope;
use App\Services\Logs\LogQuery;
use App\Services\Logs\SeverityLevel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('error-summary')]
#[Title('Error summary')]
#[Description('Group a window\'s error and fatal log lines into distinct problems: how many times each one happened, in which service, when it started and last happened, and a trace id to open. This is the "what broke?" call — reach for it before search-logs, then follow a group\'s traceId into get-trace.')]
class ErrorSummaryTool extends Tool
{
    use ResolvesScope;

    /**
     * How many rows are sampled before grouping.
     *
     * The window's errors are read once and folded in PHP; a busy hour is
     * summarised from its first thousand rather than paged through.
     */
    private const SAMPLE = 1000;

    /**
     * How much of a message identifies the problem it describes.
     */
    private const SIGNATURE_LENGTH = 120;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, LogQuery $logs): Response
    {
        $scope = $this->resolveScope($request);

        if ($scope instanceof Response) {
            return $scope;
        }

        $request->validate([
            'severity' => ['sometimes', 'string', 'in:'.implode(',', SeverityLevel::values())],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        [$from, $to] = $this->window($request);

        $minimum = SeverityLevel::tryFrom((string) $request->get('severity', SeverityLevel::Error->value))
            ?? SeverityLevel::Error;

        $result = $logs->errorSamples($scope->projectIds, $from, $to, self::SAMPLE, $minimum);

        $groups = [];

        foreach ($result['rows'] as $row) {
            $signature = $row['serviceName'].'|'.mb_substr($row['body'], 0, self::SIGNATURE_LENGTH);

            if (! isset($groups[$signature])) {
                $groups[$signature] = [
                    'service' => $row['serviceName'],
                    'severity' => $row['severityText'],
                    'message' => $row['body'],
                    'count' => 0,
                    'firstSeen' => $row['timestamp'],
                    'lastSeen' => $row['timestamp'],
                    'traceId' => $row['traceId'] !== '' ? $row['traceId'] : null,
                ];
            }

            $group = &$groups[$signature];
            $group['count']++;
            // Rows arrive newest first, so the earliest one seen closes the range.
            $group['firstSeen'] = $row['timestamp'];
            $group['traceId'] ??= ($row['traceId'] !== '' ? $row['traceId'] : null);
            unset($group);
        }

        $groups = array_values($groups);
        usort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $limit = (int) ($request->get('limit') ?? 20);

        return Response::json([
            'team' => $scope->team->slug,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'minimumSeverity' => $minimum->value,
            'sampled' => count($result['rows']),
            'truncated' => count($result['rows']) >= self::SAMPLE,
            'unavailable' => $result['unavailable'],
            'groups' => array_slice($groups, 0, $limit),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team' => $schema->string()->description('Team slug. Omit to use the current team.'),
            'project' => $schema->string()->description('Project slug. Omit to read every project in the team.'),
            'severity' => $schema->string()
                ->enum(SeverityLevel::values())
                ->description('The lowest severity to count. Defaults to "error", which also includes fatal.'),
            'from' => $schema->string()->description('Start of the window, ISO-8601. Defaults to an hour before "to".'),
            'to' => $schema->string()->description('End of the window, ISO-8601. Defaults to now.'),
            'limit' => $schema->integer()->description('Distinct problems to return, 1–50. Defaults to 20.'),
        ];
    }
}
