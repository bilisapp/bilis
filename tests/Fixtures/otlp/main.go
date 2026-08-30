// Regenerates the OTLP fixtures next to this file.
//
// The .bin files are protobuf bodies, the .json files the OTLP/JSON encoding of
// the very same messages; the decoder tests assert both map to identical rows,
// which is only worth anything because these bytes were not produced by the
// code under test. `otlp-logs-export.bin` is captured off the wire from a real
// go.opentelemetry.io/otel/exporters/otlp/otlplog/otlploghttp exporter posting
// to a local server; `otlp-logs-kitchen-sink.bin` is built from the OTLP proto
// types directly, to reach the value kinds the SDK's own API cannot express.
//
// To regenerate:
//
//	cd tests/Fixtures/otlp
//	go mod init otlpfixtures && go mod tidy && go run .
//	rm go.mod go.sum
//
// Regenerating rewrites the LOG fixtures too, and `otlp-logs-export` is not
// byte-stable: the second record sets no observed timestamp, so the SDK stamps
// it with wall-clock time. Check that file out again unless you meant to
// change it — a moving timestamp is churn, not drift.
//
// The Go module files are deliberately not committed: this is a generator, not
// part of the test suite, and the fixtures it writes are the artefact.
package main

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"time"

	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/exporters/otlp/otlplog/otlploghttp"
	"go.opentelemetry.io/otel/log"
	"go.opentelemetry.io/otel/log/global"
	sdklog "go.opentelemetry.io/otel/sdk/log"
	"go.opentelemetry.io/otel/sdk/resource"
	"go.opentelemetry.io/otel/trace"
	collogs "go.opentelemetry.io/proto/otlp/collector/logs/v1"
	"google.golang.org/protobuf/proto"
)

func main() {
	var body []byte
	var headers http.Header

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ = io.ReadAll(r.Body)
		headers = r.Header.Clone()
		w.Header().Set("Content-Type", "application/x-protobuf")
		w.WriteHeader(http.StatusOK)
		w.Write([]byte{})
	}))
	defer server.Close()

	endpoint := strings.TrimPrefix(server.URL, "http://")

	exporter, err := otlploghttp.New(context.Background(),
		otlploghttp.WithEndpoint(endpoint),
		otlploghttp.WithInsecure(),
		otlploghttp.WithURLPath("/api/v1/logs"),
	)
	if err != nil {
		panic(err)
	}

	res, _ := resource.Merge(resource.Empty(), resource.NewSchemaless(
		attribute.String("service.name", "checkout"),
		attribute.Int("deployment.generation", 41),
		attribute.Bool("service.canary", true),
	))

	provider := sdklog.NewLoggerProvider(
		sdklog.WithResource(res),
		sdklog.WithProcessor(sdklog.NewBatchProcessor(exporter)),
	)
	global.SetLoggerProvider(provider)

	logger := provider.Logger("checkout.payments", log.WithInstrumentationVersion("1.4.0"))

	traceID, _ := trace.TraceIDFromHex("5b8efff798038103d269b633813fc60c")
	spanID, _ := trace.SpanIDFromHex("eee19b7ec3c1b174")
	ctx := trace.ContextWithSpanContext(context.Background(), trace.NewSpanContext(trace.SpanContextConfig{
		TraceID:    traceID,
		SpanID:     spanID,
		TraceFlags: trace.FlagsSampled,
	}))

	record := log.Record{}
	record.SetTimestamp(time.Unix(1756211400, 123456789).UTC())
	record.SetObservedTimestamp(time.Unix(1756211401, 0).UTC())
	record.SetSeverity(log.SeverityError)
	record.SetSeverityText("ERROR")
	record.SetEventName("payment.declined")
	record.SetBody(attribute.StringValue("Card declined for order 41902"))
	record.AddAttributes(
		attribute.String("order.id", "41902"),
		attribute.Int("attempt", 3),
		attribute.Bool("retryable", true),
		attribute.Float64("amount", 19.5),
		attribute.StringSlice("tags", []string{"card", "declined"}),
	)
	logger.Emit(ctx, record)

	second := log.Record{}
	second.SetTimestamp(time.Unix(1756211402, 500000000).UTC())
	second.SetSeverity(log.SeverityWarn)
	second.SetSeverityText("WARN")
	second.SetBody(attribute.StringValue("Retrying in 8s"))
	logger.Emit(context.Background(), second)

	if err := provider.Shutdown(context.Background()); err != nil {
		panic(err)
	}

	fmt.Println("content-type:", headers.Get("Content-Type"))
	fmt.Println("content-encoding:", headers.Get("Content-Encoding"))
	fmt.Println("bytes:", len(body))

	request := &collogs.ExportLogsServiceRequest{}
	if err := proto.Unmarshal(body, request); err != nil {
		panic(err)
	}

	writePair("otlp-logs-export", request)
	kitchenSink()
	traceFixtures()
}
