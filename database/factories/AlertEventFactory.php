<?php

namespace Database\Factories;

use App\Enums\AlertEventType;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Team;
use App\Services\Alerting\Labels;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AlertEvent>
 */
class AlertEventFactory extends Factory
{
    /**
     * Define the model's default state: a rule-less firing event. Its dedupe
     * key is derived in {@see configure()} from the final attributes, so a
     * state or override applied later (`resolved()`, other labels, another
     * time) is reflected in the key. Use `forRule()` to attach it to a rule.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $labels = ['service.name' => 'checkout'];
        $occurredAt = now()->startOfMinute();

        return [
            'team_id' => Team::factory(),
            'alert_rule_id' => null,
            'alert_instance_id' => null,
            'type' => AlertEventType::Firing,
            'labels' => $labels,
            'value' => 87.0,
            'threshold' => 25.0,
            'occurred_at' => $occurredAt,
            'context' => ['logs_url' => '/logs?service=checkout&severity=error'],
            'dedupe_key' => null,
        ];
    }

    /**
     * Derive the dedupe key once every state and override has been applied,
     * the way `AlertStateMachine` builds it:
     * `rule:{rule}:{labels hash}:{type}:{occurred at}`. A rule-less event gets
     * a random suffix so fixtures never collide. An explicit key is kept.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (AlertEvent $event): void {
            if ($event->getAttribute('dedupe_key') !== null) {
                return;
            }

            $key = 'rule:'.($event->alert_rule_id ?? 0)
                .':'.Labels::hash((array) $event->labels)
                .':'.$event->type->value
                .':'.Carbon::parse($event->occurred_at)->getTimestamp();

            $event->dedupe_key = $event->alert_rule_id === null ? $key.':'.fake()->unique()->uuid() : $key;
        });
    }

    /**
     * An event raised by the given rule, on its team.
     */
    public function forRule(AlertRule $rule): static
    {
        return $this->state(fn (): array => [
            'team_id' => $rule->team_id,
            'alert_rule_id' => $rule->id,
            'threshold' => $rule->threshold,
        ]);
    }

    /**
     * The series came back under the threshold.
     */
    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'type' => AlertEventType::Resolved,
            'value' => 4.0,
        ]);
    }

    /**
     * A new issue appeared.
     */
    public function issueNew(): static
    {
        return $this->state(function (): array {
            $fingerprint = fake()->unique()->sha256();

            return [
                'type' => AlertEventType::IssueNew,
                'labels' => [],
                'value' => null,
                'threshold' => null,
                'context' => [
                    'project_id' => 1,
                    'project_slug' => 'shop',
                    'project_name' => 'Shop',
                    'fingerprint' => $fingerprint,
                    'exception_type' => 'App\\Exceptions\\PaymentDeclined',
                    'message' => 'Card 4242 was declined',
                    'service' => 'checkout',
                    'events' => 1,
                    'first_seen' => now()->toIso8601ZuluString('millisecond'),
                ],
                'dedupe_key' => 'issue:1:'.$fingerprint.':new',
            ];
        });
    }
}
