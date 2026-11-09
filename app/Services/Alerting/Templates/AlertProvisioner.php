<?php

namespace App\Services\Alerting\Templates;

use App\Enums\NotificationChannelType;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Services\Alerting\NotificationChannelConfig;
use App\Services\Logs\LogQuery;
use App\Services\Metrics\MetricQuery;
use App\Services\Traces\TraceQuery;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Makes one team match the default-alert catalogue
 * (`config/alerting_templates.php`, specs/alerting-defaults.md §3) — a
 * reconciler in the Grafana-provisioning sense: idempotent, re-run every
 * fifteen minutes, never recreating what a person turned off and never
 * overwriting what a person edited.
 *
 * 1. **Default channel, once.** While `teams.alerting_provisioned_at` is null,
 *    create the email channel "Team email" (every member, issue alerts on)
 *    and stamp the team. A team that deletes it is never given it back.
 * 2. **Templates.** A template with no rule yet is installed when its data
 *    is detected; an existing rule nobody edited (`customized_at` null) that
 *    is still on is moved to a newer `version`; an edited or turned-off rule
 *    is left alone. Detection runs only for kinds that still have a template
 *    to install, so a fully provisioned team costs no ClickHouse read.
 *
 * New template rules notify **every enabled channel of the team flagged
 * `issue_events`** — the channel created in step 1 is one, and a team that
 * moved its issue alerts to Slack gets its default alerts there too. With
 * none, the rule is created unlinked (it still shows on the Alerts page and
 * the dashboard).
 *
 * Race-safe through the `(team_id, template_key)` unique index and a
 * conditional stamp of `alerting_provisioned_at`.
 */
final class AlertProvisioner
{
    public const CHANNEL_NAME = 'Team email';

    public function __construct(
        private readonly LogQuery $logs,
        private readonly TraceQuery $traces,
        private readonly MetricQuery $metrics,
    ) {}

    /**
     * Provision one team. Never throws for a detection failure: that kind's
     * templates are skipped and retried on the next run.
     */
    public function provision(Team $team): ProvisionResult
    {
        $channelCreated = $this->provisionDefaultChannel($team);

        /** @var array<string, AlertRule> $existing */
        $existing = AlertRule::query()
            ->where('team_id', $team->id)
            ->whereNotNull('template_key')
            ->get()
            ->keyBy('template_key')
            ->all();

        $created = [];
        $upgraded = [];
        $undetectable = [];

        /** @var array<string, bool|null> $detected */
        $detected = [];
        $projectIds = null;

        foreach ($this->templates() as $key => $template) {
            $rule = $existing[$key] ?? null;

            if ($rule !== null) {
                if ($this->upgrade($rule, $template)) {
                    $upgraded[] = $key;
                }

                continue;
            }

            $kind = $this->detectionKey($template);

            if (! array_key_exists($kind, $detected)) {
                $projectIds ??= array_values($team->projects()->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());
                $detected[$kind] = $this->detect($kind, $projectIds);

                if ($detected[$kind] === null) {
                    $undetectable[] = $kind;
                }
            }

            if ($detected[$kind] === true && $this->install($team, $key, $template)) {
                $created[] = $key;
            }
        }

        return new ProvisionResult($channelCreated, $created, $upgraded, $undetectable);
    }

    /**
     * The catalogue, keyed by template key.
     *
     * @return array<string, array{version: int, requires: string, name: string, why: string, rule: array<string, mixed>}>
     */
    public function templates(): array
    {
        /** @var array<string, array{version: int, requires: string, name: string, why: string, rule: array<string, mixed>}> $templates */
        $templates = (array) config('alerting_templates.templates', []);

        return $templates;
    }

    /**
     * Create the "Team email" channel the first time a team is provisioned.
     * The stamp is a conditional update, so two concurrent runs create one.
     */
    private function provisionDefaultChannel(Team $team): bool
    {
        if ($team->alerting_provisioned_at !== null) {
            return false;
        }

        return DB::transaction(function () use ($team): bool {
            $now = now();

            $claimed = Team::query()
                ->whereKey($team->id)
                ->whereNull('alerting_provisioned_at')
                ->update(['alerting_provisioned_at' => $now]);

            $team->forceFill(['alerting_provisioned_at' => $now])->syncOriginalAttribute('alerting_provisioned_at');

            if ($claimed === 0) {
                return false;
            }

            NotificationChannel::query()->create([
                'team_id' => $team->id,
                'type' => NotificationChannelType::Email,
                'name' => self::CHANNEL_NAME,
                'config' => NotificationChannelConfig::prepare(NotificationChannelType::Email, ['recipients' => 'members']),
                'enabled' => true,
                'issue_events' => true,
            ]);

            return true;
        });
    }

    /**
     * What has to be detected before a template is installed: `logs` or
     * `traces`, or — for a host template — `hosts:{metric}`, the very metric
     * the rule reads. Host agents differ in which gauges they emit (the
     * `*.utilization` ones are opt-in in the scraper), and a rule over a
     * metric the team never sends would only ever read "no data".
     *
     * @param  array{version: int, requires: string, name: string, why: string, rule: array<string, mixed>}  $template
     */
    private function detectionKey(array $template): string
    {
        $metric = $template['rule']['query']['metric'] ?? null;

        if ($template['requires'] === 'hosts' && is_string($metric) && $metric !== '') {
            return 'hosts:'.$metric;
        }

        return $template['requires'];
    }

    /**
     * Whether the team's projects hold this kind of data; null when
     * ClickHouse could not be asked. Overload is a failure here, never a
     * guess: the query services' optimistic answers are for page chrome, and
     * a "yes" acted on would install rules for data the team never sent.
     *
     * @param  list<string>  $projectIds
     */
    private function detect(string $kind, array $projectIds): ?bool
    {
        try {
            return match (true) {
                $kind === 'logs' => $this->logs->hasAnyLogs($projectIds, throwOnOverload: true),
                $kind === 'traces' => $this->traces->hasAnyTraces($projectIds, throwOnOverload: true),
                str_starts_with($kind, 'hosts:') => $this->metrics->hasMetric($projectIds, substr($kind, strlen('hosts:'))),
                default => false,
            };
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Install a template as a new rule, due now and linked to the team's
     * issue-alert channels. False when a concurrent run got there first.
     *
     * @param  array{version: int, requires: string, name: string, why: string, rule: array<string, mixed>}  $template
     */
    private function install(Team $team, string $key, array $template): bool
    {
        return DB::transaction(function () use ($team, $key, $template): bool {
            $rule = AlertRule::query()->createOrFirst(
                ['team_id' => $team->id, 'template_key' => $key],
                [
                    ...$this->definition($template),
                    'project_ids' => null,
                    'enabled' => true,
                    'next_evaluation_at' => null,
                    'created_by' => null,
                ],
            );

            if (! $rule->wasRecentlyCreated) {
                return false;
            }

            $rule->notificationChannels()->attach(
                NotificationChannel::query()
                    ->where('team_id', $team->id)
                    ->where('enabled', true)
                    ->where('issue_events', true)
                    ->pluck('id')
                    ->all(),
            );

            return true;
        });
    }

    /**
     * Move an untouched rule to the template's newer version. Only the
     * template's own fields change — never `enabled`, channels, mute or state.
     *
     * @param  array{version: int, requires: string, name: string, why: string, rule: array<string, mixed>}  $template
     */
    private function upgrade(AlertRule $rule, array $template): bool
    {
        if ($rule->customized_at !== null || ! $rule->enabled || (int) $rule->template_version >= $template['version']) {
            return false;
        }

        /*
         * Re-read under a row lock and write in the same transaction: a user's
         * save that lands between an unlocked check and the update would
         * otherwise be overwritten with template values. Postgres blocks the
         * concurrent writer on the lock; SQLite serialises write transactions.
         */
        return DB::transaction(function () use ($rule, $template): bool {
            $current = AlertRule::query()
                ->whereKey($rule->id)
                ->whereNull('customized_at')
                ->where('enabled', true)
                ->where('template_version', $rule->template_version)
                ->lockForUpdate()
                ->first();

            return $current?->fill($this->definition($template))->save() ?? false;
        });
    }

    /**
     * The rule attributes a template owns.
     *
     * @param  array{version: int, requires: string, name: string, why: string, rule: array<string, mixed>}  $template
     * @return array<string, mixed>
     */
    private function definition(array $template): array
    {
        return [
            ...$template['rule'],
            'name' => $template['name'],
            'template_version' => $template['version'],
        ];
    }
}
