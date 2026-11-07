-- Feeds issue_summary from every otel_logs insert block that carries an
-- exception (LogWriter writes IssueFingerprint; '' means "not an issue").
--
-- The inner SELECT derives each value once under an `e`-prefixed name. The
-- outer aliases must not equal a column they aggregate, or ClickHouse resolves
-- the argument to the alias and raises ILLEGAL_AGGREGATION (the R11 trap).
--
-- Fallbacks, because only some clients send the OTel exception attributes:
--   type     exception.type, else the first `...Exception|Error` name in Body
--   message  exception.message, else Body's first line
--   origin   exception.origin (envelope clients), else the first `#N` / `at`
--            frame of exception.stacktrace, or of Body when there is none
--   user     user.id, enduser.id, user.email, user.ip — first non-empty
CREATE MATERIALIZED VIEW IF NOT EXISTS issue_summary_mv TO issue_summary AS
SELECT
    ProjectId,
    IssueFingerprint,
    min(Timestamp)                                     AS FirstSeen,
    max(Timestamp)                                     AS LastSeen,
    count()                                            AS Events,
    uniqIfState(eUser, eUser != '')                    AS Users,
    groupUniqArrayIf(ServiceName, ServiceName != '')   AS Services,
    groupUniqArrayIf(eEnvironment, eEnvironment != '') AS Environments,
    argMaxState(eType, Timestamp)                      AS ExceptionType,
    argMaxState(eMessage, Timestamp)                   AS Message,
    argMaxState(eOrigin, Timestamp)                    AS Origin,
    argMaxState(toString(ServiceName), Timestamp)      AS LastService,
    argMaxIfState(eRelease, Timestamp, eRelease != '') AS LastRelease,
    argMinIfState(eRelease, Timestamp, eRelease != '') AS FirstRelease
FROM
(
    SELECT
        ProjectId,
        IssueFingerprint,
        Timestamp,
        ServiceName,
        if(LogAttributes['exception.type'] != '',
           LogAttributes['exception.type'],
           extract(Body, '((?:[A-Za-z_][A-Za-z0-9_]*[\\\\.])*[A-Za-z_][A-Za-z0-9_]*(?:Exception|Error|Throwable|Fault))'))
            AS eType,
        leftUTF8(if(LogAttributes['exception.message'] != '',
                    LogAttributes['exception.message'],
                    splitByChar('\n', Body)[1]), 500)
            AS eMessage,
        leftUTF8(if(LogAttributes['exception.origin'] != '',
                    LogAttributes['exception.origin'],
                    trimBoth(extract(if(LogAttributes['exception.stacktrace'] != '', LogAttributes['exception.stacktrace'], Body),
                                     '(?m)^[ \\t]*(?:#\\d+|at)[ \\t]+([^\\r\\n]+)'))), 300)
            AS eOrigin,
        multiIf(LogAttributes['user.id'] != '', LogAttributes['user.id'],
                LogAttributes['enduser.id'] != '', LogAttributes['enduser.id'],
                LogAttributes['user.email'] != '', LogAttributes['user.email'],
                LogAttributes['user.ip'])
            AS eUser,
        multiIf(LogAttributes['service.version'] != '', LogAttributes['service.version'],
                ResourceAttributes['service.version'])
            AS eRelease,
        multiIf(LogAttributes['deployment.environment'] != '', LogAttributes['deployment.environment'],
                LogAttributes['deployment.environment.name'] != '', LogAttributes['deployment.environment.name'],
                ResourceAttributes['deployment.environment'] != '', ResourceAttributes['deployment.environment'],
                ResourceAttributes['deployment.environment.name'])
            AS eEnvironment
    FROM otel_logs
    WHERE IssueFingerprint != ''
)
GROUP BY ProjectId, IssueFingerprint
