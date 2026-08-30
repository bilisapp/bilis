---
title: Go
description: otlploghttp pointed straight at Bilis, or a dependency-free slog handler that batches into the simple JSON endpoint.
order: 7
---

Two routes, both good — pick by whether the service already speaks
OpenTelemetry.

- **`otlploghttp` → `/api/v1/logs`.** The standard exporter, no Collector in
  between. Bilis decodes the protobuf encoding the Go SDK sends.
- **`log/slog` → `/api/v1/ingest`.** No dependencies at all, about a hundred
  lines, works with whatever logging you already do.

## 1. OpenTelemetry: otlploghttp

The Go OTLP exporters are protobuf-only — there is no `WithProtocol`, and
`OTEL_EXPORTER_OTLP_PROTOCOL=http/json` is not a value they accept. Bilis
decodes protobuf as well as JSON, so that is no longer a reason to run a
Collector:

```bash
OTEL_SERVICE_NAME=checkout
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=https://bilis.example.com/api/v1/logs
OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer bilis_YOUR_API_KEY"
```

Or in code, which is what the environment variables end up doing anyway:

```go
exporter, err := otlploghttp.New(ctx,
	otlploghttp.WithEndpoint("bilis.example.com"),
	otlploghttp.WithURLPath("/api/v1/logs"),
	otlploghttp.WithHeaders(map[string]string{
		"Authorization": "Bearer " + os.Getenv("BILIS_API_KEY"),
	}),
	otlploghttp.WithCompression(otlploghttp.GzipCompression),
)
if err != nil {
	return err
}

provider := sdklog.NewLoggerProvider(
	sdklog.WithResource(resource.NewSchemaless(
		semconv.ServiceName("checkout"),
	)),
	sdklog.WithProcessor(sdklog.NewBatchProcessor(exporter)),
)
defer provider.Shutdown(ctx)
```

Things worth knowing about this path:

- **`OTEL_EXPORTER_OTLP_LOGS_ENDPOINT` is used verbatim**, which is why it names
  the full path. The signal-agnostic `OTEL_EXPORTER_OTLP_ENDPOINT` has the
  exporter append `/v1/logs`, so give that one the base only:
  `https://bilis.example.com/api`.
- **gzip is understood**, and worth turning on: log batches compress well.
- **gRPC is not.** `otlploggrpc` on port 4317 has nothing to talk to; use a
  Collector if you need gRPC on the application side.
- **`Shutdown` is what flushes the batch.** Without it the last export dies with
  the process, exactly as with the handler below.
- To bridge `slog` onto this exporter rather than writing OTel calls, use
  [otelslog](https://pkg.go.dev/go.opentelemetry.io/contrib/bridges/otelslog);
  the handler in the next section is the alternative that costs no dependencies.

## 2. slog handler

Create a project and an API key in the app, then put them in the environment:

```bash
BILIS_ENDPOINT=https://bilis.example.com
BILIS_API_KEY=bilis_YOUR_API_KEY
```

Drop this in `internal/bilislog/bilislog.go`. It implements `slog.Handler`, so
every `slog.Info` / `slog.With` / `slog.Group` you already wrote keeps working.

```go
package bilislog

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"sync"
	"time"
)

// Options configures the handler. Only Endpoint, APIKey and Service matter.
type Options struct {
	Endpoint      string        // Bilis origin, e.g. https://bilis.example.com
	APIKey        string        // bilis_… project key
	Service       string        // becomes the service filter in the viewer
	Level         slog.Leveler  // default slog.LevelInfo
	BatchSize     int           // default 100
	FlushInterval time.Duration // default 2s
	Client        *http.Client
}

// entry is the simple-JSON shape of /api/v1/ingest.
type entry struct {
	Message   string         `json:"message"`
	Level     string         `json:"level"`
	Timestamp string         `json:"timestamp"`
	Service   string         `json:"service,omitempty"`
	Context   map[string]any `json:"context,omitempty"`
}

type Handler struct {
	opts   Options
	ship   *shipper
	attrs  map[string]any
	prefix string
}

var _ slog.Handler = (*Handler)(nil)

func New(opts Options) *Handler {
	if opts.Level == nil {
		opts.Level = slog.LevelInfo
	}
	if opts.BatchSize <= 0 {
		opts.BatchSize = 100
	}
	if opts.FlushInterval <= 0 {
		opts.FlushInterval = 2 * time.Second
	}
	if opts.Client == nil {
		opts.Client = &http.Client{Timeout: 10 * time.Second}
	}

	return &Handler{opts: opts, ship: newShipper(opts), attrs: map[string]any{}}
}

func (h *Handler) Enabled(_ context.Context, l slog.Level) bool {
	return l >= h.opts.Level.Level()
}

func (h *Handler) Handle(_ context.Context, r slog.Record) error {
	attrs := make(map[string]any, len(h.attrs)+r.NumAttrs())
	for k, v := range h.attrs {
		attrs[k] = v
	}
	r.Attrs(func(a slog.Attr) bool {
		flatten(attrs, h.prefix, a)
		return true
	})

	e := entry{
		Message:   r.Message,
		Level:     levelName(r.Level),
		Timestamp: r.Time.UTC().Format(time.RFC3339Nano),
		Service:   h.opts.Service,
		Context:   attrs,
	}
	h.ship.enqueue(e)

	return nil
}

func (h *Handler) WithAttrs(as []slog.Attr) slog.Handler {
	c := h.clone()
	for _, a := range as {
		flatten(c.attrs, c.prefix, a)
	}

	return c
}

func (h *Handler) WithGroup(name string) slog.Handler {
	if name == "" {
		return h
	}
	c := h.clone()
	if c.prefix == "" {
		c.prefix = name
	} else {
		c.prefix += "." + name
	}

	return c
}

// Close flushes what is still buffered. Call it exactly once, at shutdown.
func (h *Handler) Close() { h.ship.close() }

func (h *Handler) clone() *Handler {
	attrs := make(map[string]any, len(h.attrs))
	for k, v := range h.attrs {
		attrs[k] = v
	}

	return &Handler{opts: h.opts, ship: h.ship, attrs: attrs, prefix: h.prefix}
}

// flatten turns nested slog groups into dotted keys: user.id, http.status.
func flatten(dst map[string]any, prefix string, a slog.Attr) {
	a.Value = a.Value.Resolve()
	if a.Equal(slog.Attr{}) {
		return
	}

	key := a.Key
	if key == "" {
		key = prefix // a group with an empty key is inlined
	} else if prefix != "" {
		key = prefix + "." + a.Key
	}

	switch a.Value.Kind() {
	case slog.KindGroup:
		for _, g := range a.Value.Group() {
			flatten(dst, key, g)
		}
	case slog.KindTime:
		dst[key] = a.Value.Time().UTC().Format(time.RFC3339Nano)
	case slog.KindDuration:
		dst[key] = a.Value.Duration().String()
	case slog.KindAny:
		// error marshals to {} otherwise — the classic structured-logging bug.
		if err, ok := a.Value.Any().(error); ok {
			dst[key] = err.Error()
		} else {
			dst[key] = a.Value.Any()
		}
	default:
		dst[key] = a.Value.Any()
	}
}

// levelName maps slog levels onto names Bilis understands. Custom levels land
// in the band below them, which is what slog itself does when it prints them.
func levelName(l slog.Level) string {
	switch {
	case l < slog.LevelDebug:
		return "trace"
	case l < slog.LevelInfo:
		return "debug"
	case l < slog.LevelWarn:
		return "info"
	case l < slog.LevelError:
		return "warn"
	default:
		return "error"
	}
}

type shipper struct {
	opts Options
	ch   chan entry
	done chan struct{}
	once sync.Once
}

func newShipper(opts Options) *shipper {
	s := &shipper{opts: opts, ch: make(chan entry, 4096), done: make(chan struct{})}
	go s.run()

	return s
}

func (s *shipper) enqueue(e entry) {
	select {
	case s.ch <- e:
	default: // buffer full: drop the line rather than block the caller
	}
}

func (s *shipper) run() {
	defer close(s.done)

	ticker := time.NewTicker(s.opts.FlushInterval)
	defer ticker.Stop()

	batch := make([]entry, 0, s.opts.BatchSize)
	flush := func() {
		if len(batch) == 0 {
			return
		}
		s.post(batch)
		batch = batch[:0]
	}

	for {
		select {
		case e, ok := <-s.ch:
			if !ok {
				flush()
				return
			}
			batch = append(batch, e)
			if len(batch) >= s.opts.BatchSize {
				flush()
			}
		case <-ticker.C:
			flush()
		}
	}
}

func (s *shipper) post(batch []entry) {
	body, err := json.Marshal(batch)
	if err != nil {
		return
	}

	req, err := http.NewRequest(http.MethodPost, s.opts.Endpoint+"/api/v1/ingest", bytes.NewReader(body))
	if err != nil {
		return
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+s.opts.APIKey)

	resp, err := s.opts.Client.Do(req)
	if err != nil {
		return // a dead log backend never breaks the program
	}
	defer resp.Body.Close()
	io.Copy(io.Discard, resp.Body)
}

func (s *shipper) close() {
	s.once.Do(func() { close(s.ch) })
	<-s.done
}
```

### Wiring it up

```go
func main() {
	bilis := bilislog.New(bilislog.Options{
		Endpoint: os.Getenv("BILIS_ENDPOINT"),
		APIKey:   os.Getenv("BILIS_API_KEY"),
		Service:  "checkout",
		Level:    slog.LevelInfo,
	})
	defer bilis.Close()

	slog.SetDefault(slog.New(bilis))

	slog.Error("Card declined for order 41902",
		"order.id", "41902",
		"attempt", 3,
		"err", errors.New("issuer declined"),
	)
}
```

That is one `202 Accepted` with a batch of one, at most two seconds later.

**Keep a local copy.** A remote log target should not be the only place your
logs exist, so fan out to stderr as well — with
[`slog-multi`](https://github.com/samber/slog-multi) that is one line:

```go
slog.SetDefault(slog.New(slogmulti.Fanout(
	slog.NewTextHandler(os.Stderr, nil),
	bilis,
)))
```

### What to know about this handler

- **`Handle` never blocks on the network.** Records go into a buffered channel
  and a single goroutine batches them, by size (100) or by time (2s).
- **A full buffer drops lines instead of stalling the request.** 4096 pending
  records means Bilis is unreachable or far slower than your log rate; blocking
  the application would be the worse failure.
- **`Close()` is not optional.** Without it, whatever is in the current batch
  dies with the process. `defer` it in `main`, and call it after your HTTP
  server's graceful shutdown returns — not before, or you lose the shutdown
  logs. Call it once: enqueueing after close panics on a closed channel.
- **Errors are stringified explicitly.** `slog.Any("err", err)` would otherwise
  marshal to `{}`, because most error types have no exported fields.
- **Ship failures are swallowed.** Retrying is safe if you want it — see
  [Limits](/docs/reference/limits-and-behavior) — but never `log` a shipping
  failure through this handler.
- **Timestamps are `time.RFC3339Nano`,** which carries an offset, so the line
  lands at event time and not at flush time. See
  [Timestamps](/docs/ingestion/timestamps).

### Trace correlation

If you already run OpenTelemetry tracing, `Handle` receives the record's
context — lift the ids out of it and Bilis will store them:

```go
type entry struct {
	// …
	TraceID string `json:"trace_id,omitempty"`
	SpanID  string `json:"span_id,omitempty"`
}

func (h *Handler) Handle(ctx context.Context, r slog.Record) error {
	// …
	if sc := trace.SpanContextFromContext(ctx); sc.IsValid() {
		e.TraceID = sc.TraceID().String()
		e.SpanID = sc.SpanID().String()
	}
	h.ship.enqueue(e)
}
```

## 3. With a Collector in front

Bilis no longer needs a Collector to translate — but a Collector is still worth
running for what it does either side of the wire: a **persistent queue** that
survives a restart, retries, and a place to add or drop attributes without
redeploying the service.

```bash
# the Go application
OTEL_SERVICE_NAME=checkout
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
```

```yaml
# the Collector
receivers:
    otlp:
        protocols:
            http: { endpoint: 0.0.0.0:4318 }
            grpc: { endpoint: 0.0.0.0:4317 }

extensions:
    file_storage:
        directory: /var/lib/otelcol/storage

exporters:
    otlphttp/bilis:
        logs_endpoint: https://bilis.example.com/api/v1/logs
        encoding: json
        headers:
            Authorization: Bearer bilis_YOUR_API_KEY
        sending_queue:
            enabled: true
            storage: file_storage
        retry_on_failure:
            enabled: true

service:
    extensions: [file_storage]
    pipelines:
        logs:
            receivers: [otlp]
            processors: []
            exporters: [otlphttp/bilis]
```

The Collector settings that decide whether you lose data under load are
explained in [Shippers](/docs/ingestion/shippers). If your Go service runs in
Docker and simply writes to stdout, you can skip the SDK entirely and have the
Collector tail the container log files instead — see
[Linux host](/docs/ingestion/linux-host).

## Which one

- Already on the OpenTelemetry SDK → **`otlploghttp` straight at Bilis**. It is
  the standard exporter and now needs nothing in between.
- Plain `slog`, no OTel anywhere → **the handler above**. It costs no
  dependencies and no container.
- Need a queue that survives a restart, or per-host enrichment → **a
  Collector**, with either of the above in front of it.

> **Note:** the protobuf encoding is decoded by Bilis itself, in PHP, and can be
> switched off per instance with `BILIS_OTLP_PROTOBUF=false` — after which an
> `otlploghttp` exporter gets `415` again. If a self-hosted instance you do not
> operate answers `415`, that is the setting to ask about. See
> [Endpoints](/docs/ingestion/endpoints).
