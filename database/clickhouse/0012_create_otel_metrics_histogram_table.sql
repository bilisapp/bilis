-- Mirrors the OpenTelemetry Collector ClickHouse exporter histogram schema
-- (metrics_histogram_table.sql). Pinned reference and the rules governing this
-- file: database/clickhouse/SCHEMA.md §2.5, R14–R16.
-- Column names and types are fixed by the exporter (R1), with one addition of
-- ours: the three DateTime columns carry an explicit 'UTC', for the reason
-- 0002 gives for Timestamp -- session_timezone governs parsing, not how a
-- naive column renders. Everything else (ORDER BY, PARTITION BY, TTL, indexes,
-- ProjectId) belongs to Bilis.
CREATE TABLE IF NOT EXISTS otel_metrics_histogram
(
    ResourceAttributes    Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    ResourceSchemaUrl     String                              CODEC(ZSTD(1)),
    ScopeName             String                              CODEC(ZSTD(1)),
    ScopeVersion          String                              CODEC(ZSTD(1)),
    ScopeAttributes       Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    ScopeDroppedAttrCount UInt32                              CODEC(ZSTD(1)),
    ScopeSchemaUrl        String                              CODEC(ZSTD(1)),
    ServiceName           LowCardinality(String)              CODEC(ZSTD(1)),
    MetricName            LowCardinality(String)              CODEC(ZSTD(1)),
    MetricDescription     String                              CODEC(ZSTD(1)),
    MetricUnit            String                              CODEC(ZSTD(1)),
    Attributes            Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    StartTimeUnix         DateTime('UTC')                     CODEC(Delta, ZSTD(1)),
    TimeUnix              DateTime('UTC')                     CODEC(Delta, ZSTD(1)),
    Count                 UInt64                              CODEC(Delta, ZSTD(1)),
    Sum                   Float64                             CODEC(ZSTD(1)),
    BucketCounts          Array(UInt64)                       CODEC(ZSTD(1)),
    ExplicitBounds        Array(Float64)                      CODEC(ZSTD(1)),
    Exemplars Nested (
        FilteredAttributes Map(LowCardinality(String), String),
        TimeUnix           DateTime('UTC'),
        Value              Float64,
        SpanId             String,
        TraceId            String
    ) CODEC(ZSTD(1)),
    Flags                 UInt32                              CODEC(ZSTD(1)),
    Min                   Float64                             CODEC(ZSTD(1)),
    Max                   Float64                             CODEC(ZSTD(1)),
    AggregationTemporality Int32                              CODEC(ZSTD(1)),

    -- Ours (R2): written explicitly on every insert, never read from a payload.
    ProjectId             LowCardinality(String) DEFAULT ''   CODEC(ZSTD(1)),

    INDEX idx_res_attr_key     mapKeys(ResourceAttributes)   TYPE bloom_filter(0.01) GRANULARITY 1,
    INDEX idx_res_attr_value   mapValues(ResourceAttributes) TYPE bloom_filter(0.01) GRANULARITY 1,
    INDEX idx_scope_attr_key   mapKeys(ScopeAttributes)      TYPE bloom_filter(0.01) GRANULARITY 1,
    INDEX idx_scope_attr_value mapValues(ScopeAttributes)    TYPE bloom_filter(0.01) GRANULARITY 1,
    INDEX idx_attr_key         mapKeys(Attributes)           TYPE bloom_filter(0.01) GRANULARITY 1,
    INDEX idx_attr_value       mapValues(Attributes)         TYPE bloom_filter(0.01) GRANULARITY 1,

    INDEX idx_service ServiceName TYPE set(100) GRANULARITY 4
)
-- Ours, and a deliberate divergence from upstream's
-- (ServiceName, MetricName, toStartOfHour(TimeUnix), cityHash64(Attributes), TimeUnix):
-- upstream has no tenant column. Every Bilis read names one metric inside
-- one team's projects over a window, so (ProjectId, MetricName) is a seek and
-- TimeUnix prunes the window; ServiceName sits between them because a
-- service filter is the next most common narrowing. Clustering, never
-- isolation (R3).
ENGINE = MergeTree
PARTITION BY toDate(TimeUnix)
ORDER BY (ProjectId, MetricName, ServiceName, TimeUnix)
TTL TimeUnix + toIntervalDay(30)
SETTINGS index_granularity = 8192,
         ttl_only_drop_parts = 1,
         non_replicated_deduplication_window = 1000
