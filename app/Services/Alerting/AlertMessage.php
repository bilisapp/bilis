<?php

namespace App\Services\Alerting;

use App\Enums\AlertCondition;
use App\Enums\AlertEventType;
use App\Enums\AlertSeverity;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Team;
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
        $name = $rule !== null ? $rule->name : ($isIssue ? 'Issue' : 'Deleted rule');

        $explorer = $rule !== null && $from !== null && $to !== null
            ? AlertExplorerLink::forRule($rule, $team, $labels, $from, $to)
            : self::issuesLink($event, $team);

        // The rule's editor, which also lists what it has firing now.
        $ruleUrl = $rule !== null
            ? route('alerts.edit', ['current_team' => $team->slug, 'alertRule' => $rule->id])
            : null;

        $facts = $isIssue ? self::issueFacts($event, $context) : array_filter([
            'Value' => $event->value !== null ? self::number($event->value) : null,
            'Threshold' => $rule !== null && $event->threshold !== null
                ? $rule->condition->value.' '.self::number($event->threshold)
                : null,
            'Severity' => $rule?->severity->value,
            'Window' => $from !== null && $to !== null ? self::windowText($from, $to) : null,
            'Labels' => $labels !== [] ? self::labelText($labels) : null,
        ], fn (?string $value): bool => $value !== null);

        return new self(
            type: 'alert.'.$event->type->value,
            title: $isIssue
                ? self::issueTitle($event, $context)
                : '['.strtoupper(str_replace('_', ' ', $event->type->value)).'] '.$name.($labels !== [] ? ' — '.self::labelText($labels) : ''),
            headline: self::headline($event, $rule, $context),
            facts: $facts,
            url: $explorer,
            state: $state,
            severity: $rule?->severity,
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
                'condition' => $rule?->condition->value,
                'state' => $state,
                'severity' => $rule?->severity->value,
                'window' => $from !== null && $to !== null
                    ? ['from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString()]
                    : null,
                'error' => $event->type === AlertEventType::RuleError && is_string($context['error'] ?? null) ? $context['error'] : null,
                'issue' => $isIssue ? self::issuePayload($context) : null,
                'links' => ['explorer' => $explorer, 'rule' => $ruleUrl],
            ],
            footer: $rule !== null && $rule->isTemplate() && $ruleUrl !== null
                ? self::TEMPLATE_FOOTER.' '.$ruleUrl
                : null,
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
                'links' => ['explorer' => null, 'rule' => null],
            ],
        );
    }

    /**
     * A number as a person reads it: no float noise, at most four decimals.
     */
    public static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

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
    private static function headline(AlertEvent $event, ?AlertRule $rule, array $context): string
    {
        $noData = ($context['no_data'] ?? false) === true || $event->value === null;
        $comparison = $rule !== null && $event->threshold !== null
            ? self::comparison($rule->condition).' the threshold of '.self::number($event->threshold)
            : null;

        return match ($event->type) {
            AlertEventType::Firing => match (true) {
                $noData => 'No data arrived in the window, and the rule treats that as firing.',
                $comparison !== null => 'The value is '.self::number((float) $event->value).", {$comparison}.",
                default => 'The value is '.self::number((float) $event->value).'.',
            },
            AlertEventType::Resolved => match (true) {
                $noData => 'Resolved: no data arrived in the window.',
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
