<?php

namespace App\Mcp\Tools;

use App\Enums\AlertState;
use App\Mcp\Concerns\ResolvesScope;
use App\Models\AlertEvent;
use App\Models\AlertInstance;
use App\Services\Alerting\AlertMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-alerts')]
#[Title('List alerts')]
#[Description('What is alerting in a team right now, and what happened recently. Returns every firing and pending alert (rule name, source, the series\' labels, its last value, the threshold and since when), then the alert history over a window (default the last 24 hours): firing, resolved, rule errors, and new or regressed issues. Read-only: it never mutes, acknowledges or edits a rule. Follow a firing alert into search-logs, list-traces or query-metric for the same labels to see why.')]
#[IsReadOnly]
class ListAlertsTool extends Tool
{
    use ResolvesScope;

    /**
     * The longest history window one call reads.
     */
    public const int MAX_WINDOW_DAYS = 7;

    /**
     * The most active instances returned, firing first.
     */
    private const int MAX_INSTANCES = 200;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $scope = $this->resolveScope($request);

        if ($scope instanceof Response) {
            return $scope;
        }

        $request->validate([
            'state' => ['sometimes', 'string', 'in:all,firing,pending'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        [$from, $to] = $this->window($request, 24 * 60);

        if ($from->lt($to->copy()->subDays(self::MAX_WINDOW_DAYS))) {
            $from = $to->copy()->subDays(self::MAX_WINDOW_DAYS);
        }

        $state = (string) ($request->get('state') ?? 'all');
        $limit = (int) ($request->get('limit') ?? 50);
        $now = now();

        $instances = AlertInstance::query()
            ->with('rule')
            ->whereHas('rule', fn ($query) => $query->where('team_id', $scope->team->getKey()))
            ->whereIn('state', match ($state) {
                'firing' => [AlertState::Firing],
                'pending' => [AlertState::Pending],
                default => [AlertState::Firing, AlertState::Pending],
            })
            ->get()
            ->sortBy(fn (AlertInstance $instance): array => [
                $instance->state === AlertState::Firing ? 0 : 1,
                ($instance->fired_at ?? $instance->active_at)?->getTimestamp() ?? 0,
            ])
            ->take(self::MAX_INSTANCES)
            ->values();

        $events = AlertEvent::query()
            ->with(['rule', 'team'])
            ->where('team_id', $scope->team->getKey())
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        return Response::json([
            'team' => $scope->team->slug,
            'active' => $instances->map(fn (AlertInstance $instance): array => [
                'rule' => $instance->rule->name,
                'source' => $instance->rule->source,
                'severity' => $instance->rule->severity->value,
                'state' => $instance->state->value,
                'labels' => (object) $instance->labels,
                'value' => $instance->value,
                'condition' => $instance->rule->condition->value,
                'threshold' => $instance->rule->threshold,
                'since' => ($instance->state === AlertState::Firing ? $instance->fired_at : $instance->active_at)?->toIso8601String(),
                'muted' => $instance->rule->isMutedAt($now),
            ])->all(),
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'events' => $events->take($limit)->map(function (AlertEvent $event): array {
                $message = AlertMessage::fromEvent($event);

                return [
                    'type' => $event->type->value,
                    'occurredAt' => $event->occurred_at->toIso8601String(),
                    'title' => $message->title,
                    'summary' => $message->headline,
                    'rule' => $event->rule?->name,
                    'labels' => (object) $event->labels,
                    'value' => $event->value,
                    'threshold' => $event->threshold,
                    'issue' => $message->payload['issue'] ?? null,
                    'url' => $message->url,
                ];
            })->values()->all(),
            'truncated' => $events->count() > $limit,
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
            'team' => $schema->string()->description('Team slug. Omit to use the current team. Alert rules belong to a team, not a project.'),
            'state' => $schema->string()
                ->enum(['all', 'firing', 'pending'])
                ->description('Which active alerts to return. Defaults to "all" (firing and pending; pending is waiting out the rule\'s "for").'),
            'from' => $schema->string()->description('Start of the history window, ISO-8601. Defaults to 24 hours before "to"; at most 7 days before it.'),
            'to' => $schema->string()->description('End of the history window, ISO-8601. Defaults to now.'),
            'limit' => $schema->integer()->description('History events to return, newest first, 1–200. Defaults to 50.'),
        ];
    }
}
