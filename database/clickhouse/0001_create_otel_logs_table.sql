CREATE TABLE IF NOT EXISTS otel_logs
(
    ProjectId UInt64,
    Timestamp DateTime64(9),
    ObservedTimestamp DateTime64(9),
    TraceId String,
    SpanId String,
    TraceFlags UInt8,
    SeverityText LowCardinality(String),
    SeverityNumber UInt8,
    ServiceName LowCardinality(String),
    Body String,
    ScopeName String,
    ScopeVersion String,
    ResourceAttributes Map(LowCardinality(String), String),
    LogAttributes Map(LowCardinality(String), String),
    INDEX idx_body Body TYPE tokenbf_v1(32768, 3, 0) GRANULARITY 1,
    INDEX idx_trace_id TraceId TYPE bloom_filter(0.001) GRANULARITY 1
)
ENGINE = MergeTree
PARTITION BY toDate(Timestamp)
ORDER BY (ProjectId, ServiceName, toDateTime(Timestamp))
SETTINGS index_granularity = 8192
