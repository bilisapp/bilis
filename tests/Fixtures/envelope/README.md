# Envelope fixtures

`laravel-exception.envelope` is what a `sentry-laravel` client sends for an
unhandled `QueryException`: an envelope header, a length-prefixed `event` item
and a `client_report` item Bilis does not store.

Regenerate it by pointing a real client's DSN at a local endpoint that dumps
the raw request body — the point of the file is that it is a client's bytes and
not our idea of them.
