<?php

namespace Database\Factories;

use App\Enums\NotificationChannelType;
use App\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    /**
     * Define the model's default state: email to the on-call address.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => NotificationChannelType::Email,
            'name' => 'On-call inbox',
            'config' => ['addresses' => ['oncall@'.fake()->domainName()]],
            'enabled' => true,
            'issue_events' => false,
            'last_failed_at' => null,
            'last_error' => null,
        ];
    }

    /**
     * A Slack incoming webhook.
     */
    public function slack(): static
    {
        return $this->state(fn (): array => [
            'type' => NotificationChannelType::Slack,
            'name' => '#alerts',
            'config' => ['url' => 'https://hooks.slack.com/services/T0000/B0000/'.fake()->regexify('[A-Za-z0-9]{24}')],
        ]);
    }

    /**
     * A signed generic webhook (Standard Webhooks).
     */
    public function webhook(): static
    {
        return $this->state(fn (): array => [
            'type' => NotificationChannelType::Webhook,
            'name' => 'Incident bridge',
            'config' => [
                'url' => 'https://hooks.'.fake()->domainName().'/bilis',
                'secret' => 'whsec_'.base64_encode(random_bytes(24)),
            ],
        ]);
    }

    /**
     * Also receives new and regressed issue events.
     */
    public function withIssueEvents(): static
    {
        return $this->state(fn (): array => ['issue_events' => true]);
    }

    /**
     * Switched off.
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    /**
     * Last delivery failed for good.
     */
    public function failing(): static
    {
        return $this->state(fn (): array => [
            'last_failed_at' => now(),
            'last_error' => 'HTTP 410 Gone',
        ]);
    }
}
