<?php

namespace App\Services\Alerting;

use App\Enums\AlertCondition;
use App\Enums\AlertEventType;
use App\Enums\AlertSeverity;
use App\Models\AlertEvent;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Services\Alerting\Sources\AlertExample;
use Carbon\CarbonImmutable;

/**
 * What one notification says, shaped once so email, Slack and the webhook
 * say the same thing (specs/alerting.md, "Notifications").
 *
 * `$payload` is the Standard Webhooks `data` object; the other fields are the
 * human rendering of it.
 */
final readonly class AlertMessage
{
    /**
     * What every notification from a template rule ends with.
     */
    public const TEMPLATE_FOOTER = 'Bilis set this alert up automatically. Tune it or turn it off:';

    /**
     * @param  string  $type  the webhook event type, e.g. `alert.firing`
     * @param  string  $title  one line: `[FIRING] Checkout error spike — service.name=checkout`
     * @param  string  $headline  one sentence on what happened
     * @param  array<string, string>  $facts  label => value, in display order
     * @param  string|null  $url  the explorer over the evaluated window
     * @param  array<string, mixed>  $payload
     * @param  string|null  $footer  the last line, for a rule Bilis set up itself (specs/alerting-defaults.md §4)
     * @param  list<AlertExample>  $examples  what the window was made of, most first (firing events only)
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $headline,
        public array $facts,
        public ?string $url,
        public string $state,
        public ?AlertSeverity $severity,
        public CarbonImmutable $occurredAt,
        public array $payload,
        public ?string $footer = null,
        public array $examples = [],
    ) {}

    /**
     * The message for a recorded event. The rule may be gone (history
     * outlives it) and issue events never had one.
     */
    public static function fromEvent(AlertEvent $event): self
    {
        $rule = $event->rule;
        $team = $event->team;
        $context = $event->context ?? [];
        $labels = $event->labels;
        $occurredAt = CarbonImmutable::instance($event->occurred_at);
        [$from, $to] = self::window($context);

        $state = match ($event->type) {
            AlertEventType::Firing => 'firing',
            AlertEventType::Resolved => 'resolved',
            AlertEventType::RuleError => 'error',
            AlertEventType::IssueNew => 'new',
            AlertEventType::IssueRegressed => 'regressed',
        };

        $isIssue = self::isIssueEvent($event);
        // Judged as recorded: the threshold, comparison and severity were
        // frozen on the event, so a rule edited since never rewrites it.
        $condition = $event->judgedCondition();
        $severity = $event->judgedSeverity();
        $name = $rule !== null ? $rule->name : ($isIssue ? 'Issue' : 'Deleted rule');

        $explorer = $rule !== null && $from !== null && $to !== null
            ? AlertExplorerLink::forRule($rule, $team, $labels, $from, $to)
            : self::issuesLink($event, $team);

        // The rule's live page: its series, its chart and what it is catching now.
        $ruleUrl = $rule !== null
            ? route('alerts.show', ['current_team' => $team->slug, 'alertRule' => $rule->id])
            : null;
        $editUrl = $rule !== null
            ? route('alerts.edit', ['current_team' => $team->slug, 'alertRule' => $rule->id])
            : null;

        $offset = self::compareOffset($context);
        $multiple = $offset !== null ? '× '.self::offsetText($offset) : '';
        $examples = $event->type === AlertEventType::Firing ? self::examples($context) : [];

        $facts = $isIssue ? self::issueFacts($event, $context) : array_filter([
            'Value' => $event->value !== null ? self::number($event->value).$multiple : null,
            'Counted' => self::countedText($event, $context, $offset),
            'Threshold' => $condition !== null && $event->threshold !== null
                ? $condition->value.' '.self::number($event->threshold).$multiple
                : null,
            'Severity' => $severity?->value,
            'Window' => $from !== null && $to !== null ? self::windowText($from, $to) : null,
            'Labels' => $labels !== [] ? self::labelText($labels) : null,
        ], fn (?string $value): bool => $value !== null);

        return new self(
            type: 'alert.'.$event->type->value,
            title: $isIssue
                ? self::issueTitle($event, $context)
                : '['.strtoupper(str_replace('_', ' ', $event->type->value)).'] '.$name.($labels !== [] ? ' — '.self::labelText($labels) : ''),
            headline: self::headline($event, $condition, $context),
            facts: $facts,
            url: $explorer,
            state: $state,
            severity: $severity,
            occurredAt: $occurredAt,
            payload: [
                'event_id' => $event->id,
                'rule' => $rule === null ? null : [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'severity' => $rule->severity->value,
                    'template_key' => $rule->template_key,
                ],
                'labels' => (object) $labels,
                'value' => $event->value,
                'threshold' => $event->threshold,
                'condition' => $condition?->value,
                'state' => $state,
                'severity' => $severity?->value,
                'window' => $from !== null && $to !== null
                    ? ['from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString()]
                    : null,
                'error' => $event->type === AlertEventType::RuleError && is_string($context['error'] ?? null) ? $context['error'] : null,
                'issue' => $isIssue ? self::issuePayload($context) : null,
                'reading' => $isIssue ? null : self::readingPayload($context),
                'examples' => array_map(fn (AlertExample $example): array => $example->toArray(), $examples),
                'links' => ['explorer' => $explorer, 'rule' => $ruleUrl, 'edit' => $editUrl],
            ],
            footer: $rule !== null && $rule->isTemplate() && $editUrl !== null
                ? self::TEMPLATE_FOOTER.' '.$editUrl
                : null,
            examples: $examples,
        );
    }

    /**
     * A clearly-labelled message that exists only to prove a channel works.
     */
    public static function test(NotificationChannel $channel): self
    {
        $now = CarbonImmutable::now();

        return new self(
            type: 'alert.test',
            title: '[TEST] Bilis notification test',
            headline: "This is a test notification for the \"{$channel->name}\" channel. Nothing is firing.",
            facts: [],
            url: null,
            state: 'test',
            severity: null,
            occurredAt: $now,
            payload: [
                'event_id' => null,
                'rule' => null,
                'labels' => (object) [],
                'value' => null,
                'threshold' => null,
                'condition' => null,
                'state' => 'test',
                'severity' => null,
                'window' => null,
                'error' => null,
                'issue' => null,
                'reading' => null,
                'examples' => [],
                'links' => ['explorer' => null, 'rule' => null, 'edit' => null],
            ],
        );
    }

    /**
     * A number as a person reads it: no float noise, at most four decimals —
     * except below 0.0001, where four decimals would print a real value as
     * `0`, so it keeps three significant digits (`0.0000312`).
     */
    public static function number(float $value): string
    {
        $magnitude = abs($value);
        $decimals = $magnitude > 0 && $magnitude < 0.0001
            ? min(20, 2 - (int) floor(log10($magnitude)))
            : 4;

        $text = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    /**
     * @param  array<string, string>  $labels
     */
    public static function labelText(array $labels): string
    {
        ksort($labels, SORT_STRING);

        return implode(', ', array_map(
            fn (string $key, string $value): string => "{$key}={$value}",
            array_keys($labels),
            $labels,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function headline(AlertEvent $event, ?AlertCondition $condition, array $context): string
    {
        $noData = ($context['no_data'] ?? false) === true || $event->value === null;
        $offset = self::compareOffset($context);
        $multiple = $offset !== null ? '×' : '';
        $comparison = $condition !== null && $event->threshold !== null
            ? self::comparison($condition).' the threshold of '.self::number($event->threshold).$multiple
            : null;
        $belowMinimum = ($context['below_minimum'] ?? false) === true && is_int($context['events'] ?? null);

        return match ($event->type) {
            AlertEventType::Firing => match (true) {
                $noData => 'No data arrived in the window, and the rule treats that as firing.',
                $offset !== null => self::comparedHeadline($event, $context, $offset, $comparison),
                $comparison !== null => 'The value is '.self::number((float) $event->value).", {$comparison}.",
                default => 'The value is '.self::number((float) $event->value).'.',
            },
            AlertEventType::Resolved => match (true) {
                $belowMinimum => 'Resolved: only '.self::rows($context['events'], $context)
                    .' in the window'
                    .(is_int($context['min_events'] ?? null) ? ', under the rule\'s minimum of '.number_format($context['min_events']) : '')
                    .' — too few to judge, so the rule stopped firing.',
                $noData => in_array($context['source'] ?? null, ['logs', 'traces'], true)
                    ? 'Resolved: nothing matched in the window.'
                    : 'Resolved: no data arrived in the window.',
                $offset !== null => 'Resolved: back to '.self::number((float) $event->value).'× '.self::offsetText($offset)
                    .(is_int($context['events'] ?? null) ? ' ('.self::rows($context['events'], $context).' in the window)' : '').'.',
                default => 'Resolved: the value is back to '.self::number((float) $event->value).'.',
            },
            AlertEventType::RuleError => 'The rule could not be evaluated: '
                .(is_string($context['error'] ?? null) ? rtrim($context['error'], '.') : 'unknown error').'.',
            AlertEventType::IssueNew => self::issueWhere('A new issue was seen', $context)
                .(is_int($context['events'] ?? null) ? ' — '.self::eventCount($context['events']).' so far.' : '.'),
            AlertEventType::IssueRegressed => self::issueWhere('A resolved issue was seen again', $context)
                .(is_string($context['resolved_at'] ?? null)
                    ? ', after it was resolved on '.CarbonImmutable::parse($context['resolved_at'])->utc()->format('Y-m-d H:i').' UTC.'
                    : '.'),
        };
    }

    /**
     * A compared reading in words: `21 matching log lines in the window,
     * against none at the same time last week — 21×, above the threshold of
     * 3×.` A count names both counts; a ratio or a percentile, whose baseline
     * is not a count, names the multiple only.
     *
     * @param  array<string, mixed>  $context
     */
    private static function comparedHeadline(AlertEvent $event, array $context, int $offset, ?string $comparison): string
    {
        $value = self::number((float) $event->value).'×';
        $then = 'at the same time '.self::offsetText($offset);
        $tail = $comparison !== null ? " — {$value}, {$comparison}." : " — {$value}.";

        if (self::isComparedCount($event) && is_int($context['events'] ?? null) && is_numeric($context['baseline'] ?? null)) {
            $baseline = (int) $context['baseline'];

            return ucfirst(self::rows($context['events'], $context)).' in the window, against '
                .($baseline === 0 ? 'none' : number_format($baseline))." {$then}{$tail}";
        }

        return "The reading is {$value} what it was {$then}".($comparison !== null ? ", {$comparison}." : '.');
    }

    /**
     * Whether the rule compared event counts (`count`/`rate`), whose baseline
     * is a number of rows rather than a value.
     */
    private static function isComparedCount(AlertEvent $event): bool
    {
        return in_array($event->rule?->query['aggregation'] ?? null, ['count', 'rate'], true);
    }

    /**
     * `21 matching log lines`, `1 span`.
     *
     * @param  array<string, mixed>  $context
     */
    private static function rows(int $count, array $context): string
    {
        $noun = match ($context['source'] ?? null) {
            'logs' => $count === 1 ? 'matching log line' : 'matching log lines',
            'traces' => $count === 1 ? 'span' : 'spans',
            default => $count === 1 ? 'event' : 'events',
        };

        return number_format($count).' '.$noun;
    }

    /**
     * The "Counted" fact: how many rows the reading was over, and the
     * baseline's count for a compared count rule.
     *
     * @param  array<string, mixed>  $context
     */
    private static function countedText(AlertEvent $event, array $context, ?int $offset): ?string
    {
        if (! is_int($context['events'] ?? null) || ! in_array($context['source'] ?? null, ['logs', 'traces'], true)) {
            return null;
        }

        $text = self::rows($context['events'], $context).' in the window';

        if ($offset !== null && self::isComparedCount($event) && is_numeric($context['baseline'] ?? null)) {
            $text .= ', '.number_format((int) $context['baseline']).' '.self::offsetText($offset);
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function compareOffset(array $context): ?int
    {
        return is_int($context['compare_offset_seconds'] ?? null) && $context['compare_offset_seconds'] > 0
            ? $context['compare_offset_seconds']
            : null;
    }

    private static function offsetText(int $seconds): string
    {
        return match ($seconds) {
            86_400 => 'yesterday',
            604_800 => 'last week',
            default => self::number($seconds / 3600).' h earlier',
        };
    }

    /**
     * The webhook's `reading` object: the counts behind the value.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function readingPayload(array $context): array
    {
        return [
            'events' => is_int($context['events'] ?? null) ? $context['events'] : null,
            'baseline' => is_numeric($context['baseline'] ?? null) ? (float) $context['baseline'] : null,
            'compare_offset_seconds' => self::compareOffset($context),
            'min_events' => is_int($context['min_events'] ?? null) ? $context['min_events'] : null,
            'below_minimum' => ($context['below_minimum'] ?? false) === true,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<AlertExample>
     */
    private static function examples(array $context): array
    {
        $examples = is_array($context['examples'] ?? null) ? $context['examples'] : [];

        return array_values(array_filter(array_map(AlertExample::fromArray(...), $examples)));
    }

    /**
     * One example as a line: `333 × Failed to write OTLP metrics (bilis) — 3 failed · p95 1.2 s`.
     */
    public static function exampleLine(AlertExample $example): string
    {
        return number_format($example->count).' × '.$example->title
            .($example->service !== null ? " ({$example->service})" : '')
            .($example->detail !== null ? " — {$example->detail}" : '');
    }

    private static function comparison(AlertCondition $condition): string
    {
        return match ($condition) {
            AlertCondition::GreaterThan => 'above',
            AlertCondition::GreaterThanOrEqual => 'at or above',
            AlertCondition::LessThan => 'below',
            AlertCondition::LessThanOrEqual => 'at or below',
        };
    }

    /**
     * The evaluated window from the event's context.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private static function window(array $context): array
    {
        $window = $context['window'] ?? null;

        if (! is_array($window) || ! is_string($window['from'] ?? null) || ! is_string($window['to'] ?? null)) {
            return [null, null];
        }

        return [CarbonImmutable::parse($window['from'])->utc(), CarbonImmutable::parse($window['to'])->utc()];
    }

    private static function windowText(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $minutes = (int) round($from->diffInSeconds($to) / 60);
        $length = $minutes >= 60 && $minutes % 60 === 0 ? ($minutes / 60).' h' : $minutes.' min';

        return $from->format('Y-m-d H:i').' – '.$to->format($from->isSameDay($to) ? 'H:i' : 'Y-m-d H:i')." UTC ({$length})";
    }

    /**
     * Issue events link to the issue's own page when the event names it,
     * otherwise to the team's issue list.
     */
    private static function issuesLink(AlertEvent $event, Team $team): ?string
    {
        if (! self::isIssueEvent($event)) {
            return null;
        }

        $context = $event->context ?? [];
        $slug = $context['project_slug'] ?? null;
        $fingerprint = $context['fingerprint'] ?? null;

        if (is_string($slug) && $slug !== '' && is_string($fingerprint) && $fingerprint !== '') {
            return route('issues.show', ['current_team' => $team->slug, 'project' => $slug, 'fingerprint' => $fingerprint]);
        }

        return route('issues.index', ['current_team' => $team->slug]);
    }

    private static function isIssueEvent(AlertEvent $event): bool
    {
        return in_array($event->type, [AlertEventType::IssueNew, AlertEventType::IssueRegressed], true);
    }

    /**
     * `[NEW ISSUE] TypeError: Cannot read properties of undefined — checkout`.
     *
     * @param  array<string, mixed>  $context
     */
    private static function issueTitle(AlertEvent $event, array $context): string
    {
        $tag = $event->type === AlertEventType::IssueNew ? '[NEW ISSUE]' : '[REGRESSED ISSUE]';
        $service = self::contextString($context, 'service');

        return $tag.' '.self::issueName($context).($service !== '' ? ' — '.$service : '');
    }

    /**
     * The issue as the list names it: `Type: message`, without repeating a
     * type the message already starts with.
     *
     * @param  array<string, mixed>  $context
     */
    private static function issueName(array $context): string
    {
        $type = self::contextString($context, 'exception_type');
        $message = trim(strtok(self::contextString($context, 'message'), "\n") ?: '');

        $name = match (true) {
            $type === '' && $message === '' => 'Issue',
            $type === '' => $message,
            $message === '' || str_starts_with($message, $type) => $message !== '' ? $message : $type,
            default => $type.': '.$message,
        };

        return mb_strimwidth($name, 0, 120, '…');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function issueWhere(string $sentence, array $context): string
    {
        $project = self::contextString($context, 'project_name');

        return $project !== '' ? $sentence.' in '.$project : $sentence;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private static function issueFacts(AlertEvent $event, array $context): array
    {
        return array_filter([
            'Issue' => self::issueName($context),
            'Project' => self::contextString($context, 'project_name'),
            'Service' => self::contextString($context, 'service'),
            'Events' => is_int($context['events'] ?? null) ? number_format($context['events']) : '',
            'First seen' => is_string($context['first_seen'] ?? null)
                ? CarbonImmutable::parse($context['first_seen'])->utc()->format('Y-m-d H:i:s').' UTC'
                : '',
            'Resolved' => $event->type === AlertEventType::IssueRegressed && is_string($context['resolved_at'] ?? null)
                ? CarbonImmutable::parse($context['resolved_at'])->utc()->format('Y-m-d H:i:s').' UTC'
                : '',
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * The webhook's `issue` object.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function issuePayload(array $context): array
    {
        return [
            'project' => self::contextString($context, 'project_slug') ?: null,
            'fingerprint' => self::contextString($context, 'fingerprint') ?: null,
            'type' => self::contextString($context, 'exception_type') ?: null,
            'message' => self::contextString($context, 'message') ?: null,
            'service' => self::contextString($context, 'service') ?: null,
            'events' => is_int($context['events'] ?? null) ? $context['events'] : null,
            'first_seen' => self::contextString($context, 'first_seen') ?: null,
            'resolved_at' => self::contextString($context, 'resolved_at') ?: null,
        ];
    }

    private static function eventCount(int $events): string
    {
        return number_format($events).' '.($events === 1 ? 'event' : 'events');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function contextString(array $context, string $key): string
    {
        return is_scalar($context[$key] ?? null) ? trim((string) $context[$key]) : '';
    }
}
