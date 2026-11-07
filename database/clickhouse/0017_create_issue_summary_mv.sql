-- Feeds issue_summary from every otel_logs insert block that carries an
-- exception (LogWriter writes IssueFingerprint; '' means "not an issue").
--
-- The inner SELECT derives each value once under an `e`-prefixed name. The
-- outer aliases must not equal a column they aggregate, or ClickHouse resolves
-- the argument to the alias and raises ILLEGAL_AGGREGATION (the R11 trap).
--
-- Type and message follow ErrorFingerprinter::exceptionClass() / message()
-- step for step, so the list's headline and the issue page agree:
--   class attribute  first non-empty of exception.type, exception.class,
--                    exception_class, error.type, exception, error (log
--                    attributes before resource attributes); its
--                    ...Exception|Error|Throwable|Fault name if it has one,
--                    else the whole value when it reads as a class name
--   haystack         Body + the message attribute: the first suffixed class
--                    name, else a `Class: message` headline
--   message          first non-empty of exception.message, exception_message,
--                    error.message, message; else Body's first line
-- re2 has no lookaround, so the PHP pattern's (?<!…)/(?!…) guards are written
-- as consuming non-capturing groups; extract() returns the first capture.
--
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
    max(now('UTC'))                                    AS LastIngested,
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
        multiIf(
            extract(eClassAttribute, '(?:^|[^A-Za-z0-9_\\\\])((?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*(?:Exception|Error|Throwable|Fault))(?:[^A-Za-z0-9_]|$)') != '',
                extract(eClassAttribute, '(?:^|[^A-Za-z0-9_\\\\])((?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*(?:Exception|Error|Throwable|Fault))(?:[^A-Za-z0-9_]|$)'),
            match(trimBoth(eClassAttribute), '^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$'),
                trimBoth(eClassAttribute),
            extract(eHaystack, '(?:^|[^A-Za-z0-9_\\\\])((?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*(?:Exception|Error|Throwable|Fault))(?:[^A-Za-z0-9_]|$)') != '',
                extract(eHaystack, '(?:^|[^A-Za-z0-9_\\\\])((?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*(?:Exception|Error|Throwable|Fault))(?:[^A-Za-z0-9_]|$)'),
            extract(eHaystack, '(?m)^[ \\t]*((?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*|[A-Z][A-Za-z0-9_]*)[ \\t]*:[ \\t]'))
            AS eType,
        leftUTF8(if(eMessageAttribute != '',
                    eMessageAttribute,
                    splitByChar('\n', trimBoth(Body))[1]), 500)
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
    FROM
    (
        SELECT
            *,
            arrayFirst(value -> trimBoth(value) != '', [
                LogAttributes['exception.type'], LogAttributes['exception.class'], LogAttributes['exception_class'],
                LogAttributes['error.type'], LogAttributes['exception'], LogAttributes['error'],
                ResourceAttributes['exception.type'], ResourceAttributes['exception.class'], ResourceAttributes['exception_class'],
                ResourceAttributes['error.type'], ResourceAttributes['exception'], ResourceAttributes['error']])
                AS eClassAttribute,
            trimBoth(arrayFirst(value -> trimBoth(value) != '', [
                LogAttributes['exception.message'], LogAttributes['exception_message'],
                LogAttributes['error.message'], LogAttributes['message'],
                ResourceAttributes['exception.message'], ResourceAttributes['exception_message'],
                ResourceAttributes['error.message'], ResourceAttributes['message']]))
                AS eMessageAttribute,
            concat(Body, '\n', eMessageAttribute) AS eHaystack
        FROM otel_logs
        WHERE IssueFingerprint != ''
    )
)
GROUP BY ProjectId, IssueFingerprint
