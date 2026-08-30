<?php

namespace App\Services\Traces;

use App\Services\Logs\LogFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The user supplied, already validated criteria for a trace search.
 *
 * The twin of {@see LogFilters}, and deliberately shaped like
 * it: the two toolbars share a time-range control and a project picker, so the
 * query strings they produce have to agree.
 */
class TraceFilters
{
    /**
     * The default window when the request does not specify one.
     */
    public const DEFAULT_RANGE_MINUTES = 60;

    /**
     * The number of traces a single page may contain.
     */
    public const LIMIT = 50;

    public function __construct(
        public readonly ?string $project = null,
        public readonly ?string $service = null,
        public readonly bool $errorsOnly = false,
        public readonly ?int $minDurationMs = null,
        public readonly Carbon $from = new Carbon,
        public readonly Carbon $to = new Carbon,
        public readonly ?string $cursor = null,
        public readonly int $limit = self::LIMIT,
    ) {}

    /**
     * Build the filters from the request query string, falling back to defaults.
     */
    public static function fromRequest(Request $request): self
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'project' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            'errors' => ['nullable', 'boolean'],
            'min_duration' => ['nullable', 'integer', 'min:0', 'max:3600000'],
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

        $minDuration = $validated['min_duration'] ?? null;

        return new self(
            project: self::trimmedOrNull($validated['project'] ?? null),
            service: self::trimmedOrNull($validated['service'] ?? null),
            errorsOnly: (bool) ($validated['errors'] ?? false),
            minDurationMs: is_numeric($minDuration) && (int) $minDuration > 0 ? (int) $minDuration : null,
            from: $from,
            to: $to,
            cursor: isset($validated['cursor']) ? Carbon::parse((string) $validated['cursor'])->format('Y-m-d H:i:s.u') : null,
            limit: self::LIMIT,
        );
    }

    /**
     * The same filters with the paging cursor dropped.
     *
     * A cursor pages *backwards* through the window; a tail poll reads forwards
     * from the newest row the reader already holds. Carrying one into the other
     * asks for traces both older and newer than the same instant, which is
     * always empty.
     */
    public function withoutCursor(): self
    {
        return new self(
            project: $this->project,
            service: $this->service,
            errorsOnly: $this->errorsOnly,
            minDurationMs: $this->minDurationMs,
            from: $this->from,
            to: $this->to,
            cursor: null,
            limit: $this->limit,
        );
    }

    /**
     * The filters as they should be handed back to the client.
     *
     * @return array{project: string|null, service: string|null, errors: bool, minDuration: int|null, from: string, to: string, cursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'service' => $this->service,
            'errors' => $this->errorsOnly,
            'minDuration' => $this->minDurationMs,
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
