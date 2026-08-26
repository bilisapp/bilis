-- Mirrors the OpenTelemetry Collector ClickHouse exporter logs schema.
-- Pinned reference and the rules governing this file: database/clickhouse/SCHEMA.md.
-- Column names and types are fixed by the exporter (R1); everything else
-- (ORDER BY, PARTITION BY, TTL, indexes, ProjectId) belongs to Bilis.
CREATE TABLE IF NOT EXISTS otel_logs
(
    Timestamp          DateTime64(9)                       CODEC(Delta(8), ZSTD(1)),
    TraceId            String                              CODEC(ZSTD(1)),
    SpanId             String                              CODEC(ZSTD(1)),
    TraceFlags         UInt8,
    SeverityText       LowCardinality(String)              CODEC(ZSTD(1)),
    SeverityNumber     UInt8,
    ServiceName        LowCardinality(String)              CODEC(ZSTD(1)),
    Body               String                              CODEC(ZSTD(1)),
    ResourceSchemaUrl  LowCardinality(String)              CODEC(ZSTD(1)),
    ResourceAttributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    ScopeSchemaUrl     LowCardinality(String)              CODEC(ZSTD(1)),
    ScopeName          String                              CODEC(ZSTD(1)),
    ScopeVersion       LowCardinality(String)              CODEC(ZSTD(1)),
    ScopeAttributes    Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    LogAttributes      Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    EventName          String                              CODEC(ZSTD(1)),

    -- Bilis addition. Written explicitly by Bilis from the authenticated API key.
    -- DEFAULT '' only keeps stock-exporter INSERTs valid; it does NOT populate them.
    ProjectId          LowCardinality(String) DEFAULT ''   CODEC(ZSTD(1)),

    INDEX idx_trace_id         TraceId                       TYPE bloom_filter(0.001) GRANULARITY 1,
    INDEX idx_res_attr_key     mapKeys(ResourceAttributes)   TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_res_attr_value   mapValues(ResourceAttributes) TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_scope_attr_key   mapKeys(ScopeAttributes)      TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_scope_attr_value mapValues(ScopeAttributes)    TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_log_attr_key     mapKeys(LogAttributes)        TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_log_attr_value   mapValues(LogAttributes)      TYPE bloom_filter(0.01)  GRANULARITY 1,

    INDEX idx_service ServiceName TYPE set(100) GRANULARITY 4,

    -- ClickHouse < 26.2 only. On >= 26.2 this becomes a `text` index on Body with the
    -- lower() wrapper dropped, and LogQuery must switch to hasAnyTokens/hasAllTokens.
    -- See SCHEMA.md R5 before upgrading: the query expression must match this one exactly.
    INDEX idx_lower_body lower(Body) TYPE tokenbf_v1(32768, 3, 0) GRANULARITY 8
)
-- Deliberate divergence from upstream. Upstream leads with
-- toStartOfFiveMinutes(Timestamp) because it has no tenant column; with
-- ProjectId leading, the bucket adds little and breaks read-in-order for
-- live tail and the default "latest N logs" view. idx_service compensates
-- for service-filtered queries.
ENGINE = MergeTree
PARTITION BY toDate(Timestamp)
ORDER BY (ProjectId, Timestamp, ServiceName)
TTL toDateTime(Timestamp) + toIntervalDay(30)
SETTINGS index_granularity = 8192,
         ttl_only_drop_parts = 1,
         non_replicated_deduplication_window = 1000
