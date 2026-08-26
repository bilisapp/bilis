<?php

namespace App\Services\Logs;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The user supplied, already validated criteria for a log search.
 */
class LogFilters
{
    /**
     * The default window when the request does not specify one.
     */
    public const DEFAULT_RANGE_MINUTES = 60;

    /**
     * The number of rows a single page may contain.
     */
    public const LIMIT = 100;

    /**
     * @param  list<SeverityLevel>  $severities
     */
    public function __construct(
        public readonly ?string $project = null,
        public readonly ?string $service = null,
        public readonly array $severities = [],
        public readonly ?string $search = null,
        public readonly Carbon $from = new Carbon,
        public readonly Carbon $to = new Carbon,
        public readonly ?string $cursor = null,
        public readonly int $limit = self::LIMIT,
    ) {}

    /**
     * Build the filters from the request query string, falling back to sane defaults.
     */
    public static function fromRequest(Request $request): self
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'project' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', 'array'],
            'severity.*' => ['string', 'in:'.implode(',', SeverityLevel::values())],
            'search' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'cursor' => ['nullable', 'date'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse((string) $validated['to']) : Carbon::now();
        $from = isset($validated['from'])
            ? Carbon::parse((string) $validated['from'])
            : (clone $to)->subMinutes(self::DEFAULT_RANGE_MINUTES);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        /** @var list<string> $severity */
        $severity = array_values($validated['severity'] ?? []);

        return new self(
            project: self::trimmedOrNull($validated['project'] ?? null),
            service: self::trimmedOrNull($validated['service'] ?? null),
            severities: array_values(array_filter(array_map(
                fn (string $value): ?SeverityLevel => SeverityLevel::tryFrom($value),
                $severity,
            ))),
            search: self::trimmedOrNull($validated['search'] ?? null),
            from: $from,
            to: $to,
            cursor: isset($validated['cursor']) ? Carbon::parse((string) $validated['cursor'])->format('Y-m-d H:i:s.u') : null,
            limit: self::LIMIT,
        );
    }

    /**
     * The filters as they should be handed back to the client.
     *
     * @return array{project: string|null, service: string|null, severity: list<string>, search: string|null, from: string, to: string, cursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'service' => $this->service,
            'severity' => array_map(fn (SeverityLevel $level): string => $level->value, $this->severities),
            'search' => $this->search,
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'cursor' => $this->cursor,
        ];
    }

    private static function trimmedOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
