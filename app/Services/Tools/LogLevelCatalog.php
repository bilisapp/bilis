<?php

namespace App\Services\Tools;

use App\Services\Ingest\LogSeverity;
use App\Services\Logs\SeverityLevel;

/**
 * The level names and numbers of the logging systems people ship from, and
 * where each lands on the OpenTelemetry severity scale.
 *
 * The OTel column is never typed in here: it is whatever
 * `LogSeverity::numberForText()` makes of the level's name, which is exactly
 * what Bilis stores when a record arrives with that severity text and no
 * number. The page and the ingest path therefore cannot disagree.
 */
final class LogLevelCatalog
{
    /**
     * Native level names and values, least to most severe.
     *
     * @var array<string, array{label: string, levels: list<array{0: string, 1: int|string}>}>
     */
    public const SYSTEMS = [
        'syslog' => ['label' => 'Syslog (RFC 5424)', 'levels' => [
            ['debug', 7], ['informational', 6], ['notice', 5], ['warning', 4],
            ['error', 3], ['critical', 2], ['alert', 1], ['emergency', 0],
        ]],
        'psr3' => ['label' => 'PHP — PSR-3 / Monolog', 'levels' => [
            ['debug', 100], ['info', 200], ['notice', 250], ['warning', 300],
            ['error', 400], ['critical', 500], ['alert', 550], ['emergency', 600],
        ]],
        'python' => ['label' => 'Python — logging', 'levels' => [
            ['DEBUG', 10], ['INFO', 20], ['WARNING', 30], ['ERROR', 40], ['CRITICAL', 50],
        ]],
        'log4j' => ['label' => 'Java — Log4j 2', 'levels' => [
            ['TRACE', 600], ['DEBUG', 500], ['INFO', 400], ['WARN', 300], ['ERROR', 200], ['FATAL', 100],
        ]],
        'slog' => ['label' => 'Go — log/slog', 'levels' => [
            ['DEBUG', -4], ['INFO', 0], ['WARN', 4], ['ERROR', 8],
        ]],
        'pino' => ['label' => 'Node.js — pino', 'levels' => [
            ['trace', 10], ['debug', 20], ['info', 30], ['warn', 40], ['error', 50], ['fatal', 60],
        ]],
        'dotnet' => ['label' => '.NET — Microsoft.Extensions.Logging', 'levels' => [
            ['Trace', 0], ['Debug', 1], ['Information', 2], ['Warning', 3], ['Error', 4], ['Critical', 5],
        ]],
        'ruby' => ['label' => 'Ruby — Logger', 'levels' => [
            ['DEBUG', 0], ['INFO', 1], ['WARN', 2], ['ERROR', 3], ['FATAL', 4],
        ]],
    ];

    /**
     * Every system's levels, each with the OTel severity it maps to.
     *
     * @return array<string, array{label: string, levels: list<array{name: string, value: int|string, severityNumber: int, severityText: string, bucket: SeverityLevel}>}>
     */
    public function systems(): array
    {
        $systems = [];

        foreach (self::SYSTEMS as $key => $system) {
            $systems[$key] = [
                'label' => $system['label'],
                'levels' => array_map(fn (array $level): array => $this->describe($level[0], $level[1]), $system['levels']),
            ];
        }

        return $systems;
    }

    /**
     * The OTel bucket for a severity number, or null for 0 (unspecified) and out of range.
     */
    public static function bucket(int $severityNumber): ?SeverityLevel
    {
        foreach (SeverityLevel::cases() as $level) {
            if ($severityNumber >= $level->minimumSeverityNumber() && $severityNumber <= $level->maximumSeverityNumber()) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Everything a typed level could mean: an OTel severity text, an OTel
     * severity number, and every native level with that name or value.
     *
     * @return array{query: string, asText: array{severityNumber: int, severityText: string, bucket: SeverityLevel}|null, asNumber: array{severityNumber: int, severityText: string, bucket: SeverityLevel}|null, native: list<array{system: string, name: string, value: int|string, severityNumber: int, severityText: string, bucket: SeverityLevel}>}
     */
    public function lookup(string $query): array
    {
        $query = trim($query);
        $asText = null;
        $asNumber = null;
        $native = [];

        if ($query === '') {
            return ['query' => '', 'asText' => null, 'asNumber' => null, 'native' => []];
        }

        $number = LogSeverity::numberForText($query);

        if ($number !== null) {
            $asText = $this->severity($number);
        }

        if (preg_match('/^-?\d+$/', $query) === 1 && (int) $query >= 1 && (int) $query <= 24) {
            $asNumber = $this->severity((int) $query);
        }

        foreach ($this->systems() as $system) {
            foreach ($system['levels'] as $level) {
                $matchesName = strcasecmp($level['name'], $query) === 0;
                $matchesValue = preg_match('/^-?\d+$/', $query) === 1 && (string) $level['value'] === (string) (int) $query;

                if ($matchesName || $matchesValue) {
                    $native[] = ['system' => $system['label']] + $level;
                }
            }
        }

        return ['query' => $query, 'asText' => $asText, 'asNumber' => $asNumber, 'native' => $native];
    }

    /**
     * @return array{name: string, value: int|string, severityNumber: int, severityText: string, bucket: SeverityLevel}
     */
    private function describe(string $name, int|string $value): array
    {
        return ['name' => $name, 'value' => $value] + $this->severity((int) LogSeverity::numberForText($name));
    }

    /**
     * @return array{severityNumber: int, severityText: string, bucket: SeverityLevel}
     */
    private function severity(int $number): array
    {
        return [
            'severityNumber' => $number,
            'severityText' => LogSeverity::textForNumber($number),
            'bucket' => self::bucket($number) ?? SeverityLevel::Info,
        ];
    }
}
