-- Mirrors the OpenTelemetry Collector ClickHouse exporter traces schema.
-- Pinned reference and the rules governing this file: database/clickhouse/SCHEMA.md.
-- Column names and types are fixed by the exporter (R1); everything else
-- (ORDER BY, PARTITION BY, TTL, indexes, ProjectId) belongs to Bilis.
CREATE TABLE IF NOT EXISTS otel_traces
(
    Timestamp           DateTime64(9, 'UTC')                CODEC(Delta(8), ZSTD(1)),
    TraceId             String                              CODEC(ZSTD(1)),
    SpanId              String                              CODEC(ZSTD(1)),
    ParentSpanId        String                              CODEC(ZSTD(1)),
    TraceState          String                              CODEC(ZSTD(1)),
    SpanName            LowCardinality(String)              CODEC(ZSTD(1)),
    SpanKind            LowCardinality(String)              CODEC(ZSTD(1)),
    ServiceName         LowCardinality(String)              CODEC(ZSTD(1)),
    ResourceAttributes  Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    ScopeName           String                              CODEC(ZSTD(1)),
    ScopeVersion        String                              CODEC(ZSTD(1)),
    SpanAttributes      Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    Duration            UInt64                              CODEC(ZSTD(1)),
    StatusCode          LowCardinality(String)              CODEC(ZSTD(1)),
    StatusMessage       String                              CODEC(ZSTD(1)),

    -- Position-aligned parallel arrays (R12). Nested is the exporter's own
    -- declaration; it stores exactly the `Events.Timestamp` / `Events.Name` /
    -- `Events.Attributes` columns the insert names. Never reorder or filter one
    -- sub-column without the others -- an event's name would silently attach to
    -- another event's attributes.
    Events Nested (
        Timestamp  DateTime64(9),
        Name       LowCardinality(String),
        Attributes Map(LowCardinality(String), String)
    ) CODEC(ZSTD(1)),
    Links Nested (
        TraceId    String,
        SpanId     String,
        TraceState String,
        Attributes Map(LowCardinality(String), String)
    ) CODEC(ZSTD(1)),

    -- Bilis addition. Written explicitly by Bilis from the authenticated API key.
    -- DEFAULT '' only keeps stock-exporter INSERTs valid; it does NOT populate them.
    ProjectId           LowCardinality(String) DEFAULT ''   CODEC(ZSTD(1)),

    INDEX idx_trace_id         TraceId                       TYPE bloom_filter(0.001) GRANULARITY 1,
    INDEX idx_res_attr_key     mapKeys(ResourceAttributes)   TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_res_attr_value   mapValues(ResourceAttributes) TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_span_attr_key    mapKeys(SpanAttributes)       TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_span_attr_value  mapValues(SpanAttributes)     TYPE bloom_filter(0.01)  GRANULARITY 1,

    INDEX idx_service  ServiceName TYPE set(100) GRANULARITY 4,
    INDEX idx_duration Duration    TYPE minmax   GRANULARITY 1
)
-- Same sort key as otel_logs, and the same deliberate divergence from upstream:
-- the exporter leads with (ServiceName, SpanName, toDateTime(Timestamp)) because
-- it has no tenant column. With ProjectId leading, a trace list or a waterfall is
-- a seek rather than a scan, and idx_service compensates for service-filtered
-- queries. It is clustering, never isolation (R3).
ENGINE = MergeTree
PARTITION BY toDate(Timestamp)
ORDER BY (ProjectId, Timestamp, ServiceName)
TTL toDateTime(Timestamp) + toIntervalDay(30)
SETTINGS index_granularity = 8192,
         ttl_only_drop_parts = 1,
         non_replicated_deduplication_window = 1000
