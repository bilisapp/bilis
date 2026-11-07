-- Feeds issue_hourly; the user expression matches issue_summary_mv's.
CREATE MATERIALIZED VIEW IF NOT EXISTS issue_hourly_mv TO issue_hourly AS
SELECT
    ProjectId,
    toStartOfHour(toDateTime(Timestamp, 'UTC')) AS Hour,
    IssueFingerprint,
    count()                                     AS Events,
    uniqIfState(eUser, eUser != '')             AS Users
FROM
(
    SELECT
        ProjectId,
        IssueFingerprint,
        Timestamp,
        multiIf(LogAttributes['user.id'] != '', LogAttributes['user.id'],
                LogAttributes['enduser.id'] != '', LogAttributes['enduser.id'],
                LogAttributes['user.email'] != '', LogAttributes['user.email'],
                LogAttributes['user.ip'])
            AS eUser
    FROM otel_logs
    WHERE IssueFingerprint != ''
)
GROUP BY ProjectId, Hour, IssueFingerprint
