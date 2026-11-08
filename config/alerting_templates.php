<?php

/*
|--------------------------------------------------------------------------
| Default alert rules (specs/alerting-defaults.md §2)
|--------------------------------------------------------------------------
|
| The catalogue `alerts:provision` installs into every team whose data
| `requires` names (`logs` | `traces` | `hosts`). Each `rule` is the full set
| of AlertRule attributes; `project_ids` is always null (every project, now
| and later) and is not listed. `why` is one sentence shown beside the rule.
|
| Raise a template's `version` when you change its `rule` or `name`: rules
| nobody has edited (`customized_at` null) and that are still on are then
| updated on the next run. Edited or turned-off rules are never touched.
|
| Every `query` must pass its source's `rules()` — AlertTemplatesTest
| proves it. Thresholds are conservative on purpose: a default that pages
| too often gets turned off, and alerting with it.
|
*/

return [

    'templates' => [

        'logs.error_spike' => [
            'version' => 1,
            'requires' => 'logs',
            'name' => 'Error spike',
            'why' => 'A service logged more than three times as many errors as in the same ten minutes last week.',
            'rule' => [
                'source' => 'logs',
                'query' => [
                    'filters' => ['severities' => ['error', 'fatal']],
                    'aggregation' => 'count',
                    'group_by' => 'service.name',
                    'compare' => ['offset_seconds' => 604_800],
                    'min_events' => 20,
                ],
                'condition' => '>',
                'threshold' => 3,
                'window_seconds' => 600,
                'interval_seconds' => 300,
                'for_seconds' => 0,
                'keep_firing_for_seconds' => 600,
                'no_data_state' => 'inactive',
                'severity' => 'warning',
            ],
        ],

        'traces.error_rate' => [
            'version' => 1,
            'requires' => 'traces',
            'name' => 'High error rate',
            'why' => 'More than 5% of the requests a service handled failed for five minutes.',
            'rule' => [
                'source' => 'traces',
                'query' => [
                    'filters' => ['kind' => 'Server'],
                    'aggregation' => 'error_ratio',
                    'group_by' => 'service.name',
                    'min_events' => 50,
                ],
                'condition' => '>',
                'threshold' => 0.05,
                'window_seconds' => 600,
                'interval_seconds' => 300,
                'for_seconds' => 300,
                'keep_firing_for_seconds' => 0,
                'no_data_state' => 'inactive',
                'severity' => 'critical',
            ],
        ],

        'traces.latency_regression' => [
            'version' => 1,
            'requires' => 'traces',
            'name' => 'Slower than last week',
            'why' => 'A service\'s p95 request time is more than twice what it was at the same time last week.',
            'rule' => [
                'source' => 'traces',
                'query' => [
                    'filters' => ['kind' => 'Server'],
                    'aggregation' => 'p95',
                    'group_by' => 'service.name',
                    'compare' => ['offset_seconds' => 604_800],
                    'min_events' => 50,
                ],
                'condition' => '>',
                'threshold' => 2,
                'window_seconds' => 900,
                'interval_seconds' => 300,
                'for_seconds' => 600,
                'keep_firing_for_seconds' => 0,
                'no_data_state' => 'inactive',
                'severity' => 'warning',
            ],
        ],

        'hosts.disk_full' => [
            'version' => 1,
            'requires' => 'hosts',
            'name' => 'Disk almost full',
            'why' => 'A filesystem on a host has been more than 90% full for ten minutes.',
            'rule' => [
                'source' => 'metrics',
                'query' => [
                    'metric' => 'system.filesystem.utilization',
                    'aggregation' => 'max',
                    'group_by' => 'host.name',
                ],
                'condition' => '>',
                'threshold' => 0.9,
                'window_seconds' => 600,
                'interval_seconds' => 300,
                'for_seconds' => 600,
                'keep_firing_for_seconds' => 0,
                'no_data_state' => 'inactive',
                'severity' => 'critical',
            ],
        ],

        'hosts.memory_pressure' => [
            'version' => 1,
            'requires' => 'hosts',
            'name' => 'Memory pressure',
            'why' => 'A host has used more than 95% of its memory for ten minutes.',
            'rule' => [
                'source' => 'metrics',
                'query' => [
                    'metric' => 'system.memory.utilization',
                    'filters' => ['state' => 'used'],
                    'aggregation' => 'avg',
                    'group_by' => 'host.name',
                ],
                'condition' => '>',
                'threshold' => 0.95,
                'window_seconds' => 600,
                'interval_seconds' => 300,
                'for_seconds' => 600,
                'keep_firing_for_seconds' => 0,
                'no_data_state' => 'inactive',
                'severity' => 'warning',
            ],
        ],

        /*
         * A heartbeat: the condition can never hold for a utilization, so the
         * rule fires only on no data — a host that reported and then went
         * quiet. After `alerting.retire_after` it resolves silently and is
         * forgotten, so a decommissioned host does not fire forever.
         */
        'hosts.silent' => [
            'version' => 1,
            'requires' => 'hosts',
            'name' => 'Host stopped reporting',
            'why' => 'A host that was sending metrics has sent nothing for ten minutes.',
            'rule' => [
                'source' => 'metrics',
                'query' => [
                    'metric' => 'system.memory.utilization',
                    'aggregation' => 'last',
                    'group_by' => 'host.name',
                ],
                'condition' => '<',
                'threshold' => 0,
                'window_seconds' => 300,
                'interval_seconds' => 300,
                'for_seconds' => 600,
                'keep_firing_for_seconds' => 0,
                'no_data_state' => 'firing',
                'severity' => 'critical',
            ],
        ],

    ],

];
