// Trace fixtures, generated the same way the log ones are: a real Go exporter
// posting to a local server for the wire capture, and the OTLP proto types
// directly for the shapes an SDK cannot express.
//
// `otlp-traces-export.bin` is what go.opentelemetry.io/otel/exporters/otlp/
// otlptrace/otlptracehttp actually sends. `otlp-traces-kitchen-sink.bin` adds
// events, links, every SpanKind and every StatusCode, plus a span with no
// parent and one with a parent that is not in the batch.
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
	"go.opentelemetry.io/otel/codes"
	"go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracehttp"
	"go.opentelemetry.io/otel/sdk/resource"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
	"go.opentelemetry.io/otel/trace"
	coltrace "go.opentelemetry.io/proto/otlp/collector/trace/v1"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
	"google.golang.org/protobuf/proto"
)

func traceFixtures() {
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

	exporter, err := otlptracehttp.New(context.Background(),
		otlptracehttp.WithEndpoint(strings.TrimPrefix(server.URL, "http://")),
		otlptracehttp.WithInsecure(),
		otlptracehttp.WithURLPath("/api/v1/traces"),
	)
	if err != nil {
		panic(err)
	}

	res, _ := resource.Merge(resource.Empty(), resource.NewSchemaless(
		attribute.String("service.name", "checkout"),
		attribute.Int("deployment.generation", 41),
		attribute.Bool("service.canary", true),
	))

	provider := sdktrace.NewTracerProvider(
		sdktrace.WithResource(res),
		sdktrace.WithBatcher(exporter),
		sdktrace.WithSampler(sdktrace.AlwaysSample()),
	)

	tracer := provider.Tracer("checkout.payments", trace.WithInstrumentationVersion("1.4.0"))

	ctx, root := tracer.Start(context.Background(), "POST /checkout", trace.WithSpanKind(trace.SpanKindServer))
	root.SetAttributes(
		attribute.String("http.method", "POST"),
		attribute.Int("http.status_code", 500),
		attribute.Bool("checkout.guest", false),
		attribute.Float64("cart.total", 19.5),
	)

	_, child := tracer.Start(ctx, "charge card", trace.WithSpanKind(trace.SpanKindClient))
	child.AddEvent("retrying", trace.WithAttributes(attribute.Int("attempt", 3)))
	child.SetStatus(codes.Error, "card declined")
	child.End()

	root.SetStatus(codes.Error, "checkout failed")
	root.End()

	if err := provider.Shutdown(context.Background()); err != nil {
		panic(err)
	}

	fmt.Println("traces content-type:", headers.Get("Content-Type"))
	fmt.Println("traces bytes:", len(body))

	request := &coltrace.ExportTraceServiceRequest{}
	if err := proto.Unmarshal(body, request); err != nil {
		panic(err)
	}

	writePair("otlp-traces-export", request)
	traceKitchenSink()
}

// traceKitchenSink builds the span shapes the SDK will not emit: every kind and
// status, a link, an event with attributes, an explicitly zeroed parent span id,
// and a child whose parent is absent from the batch.
func traceKitchenSink() {
	traceID := []byte{
		0x5b, 0x8e, 0xff, 0xf7, 0x98, 0x03, 0x81, 0x03,
		0xd2, 0x69, 0xb6, 0x33, 0x81, 0x3f, 0xc6, 0x0c,
	}
	rootSpanID := []byte{0xee, 0xe1, 0x9b, 0x7e, 0xc3, 0xc1, 0xb1, 0x74}
	childSpanID := []byte{0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08}
	missingParent := []byte{0xde, 0xad, 0xbe, 0xef, 0xde, 0xad, 0xbe, 0xef}

	start := uint64(time.Unix(1756211400, 0).UnixNano())

	spans := []*tracepb.Span{
		{
			TraceId: traceID,
			SpanId:  rootSpanID,
			// Explicitly all zeroes rather than absent: the wire's other way of
			// spelling "no parent", which has to normalise to '' like an absent
			// one, or the summary view finds no root.
			ParentSpanId:      make([]byte, 8),
			Name:              "POST /checkout",
			Kind:              tracepb.Span_SPAN_KIND_SERVER,
			StartTimeUnixNano: start,
			EndTimeUnixNano:   start + 250_000_000,
			TraceState:        "vendor=1,other=2",
			Attributes: []*commonpb.KeyValue{
				{Key: "http.method", Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: "POST"}}},
				{Key: "http.status_code", Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_IntValue{IntValue: 500}}},
				{Key: "checkout.guest", Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_BoolValue{BoolValue: true}}},
				{Key: "cart.total", Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_DoubleValue{DoubleValue: 19.5}}},
				{Key: "payload", Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_BytesValue{BytesValue: []byte{0x01, 0x02}}}},
				{Key: "tags", Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_ArrayValue{ArrayValue: &commonpb.ArrayValue{
					Values: []*commonpb.AnyValue{
						{Value: &commonpb.AnyValue_StringValue{StringValue: "card"}},
						{Value: &commonpb.AnyValue_StringValue{StringValue: "declined"}},
					},
				}}}},
			},
			Events: []*tracepb.Span_Event{
				{
					TimeUnixNano: start + 10_000_000,
					Name:         "exception",
					Attributes: []*commonpb.KeyValue{
						{Key: "exception.type", Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: "RuntimeException"}}},
					},
				},
				// An event with no attributes at all: the Array(Map) element that
				// has to serialize as {} rather than [].
				{TimeUnixNano: start + 20_000_000, Name: "retrying"},
			},
			Links: []*tracepb.Span_Link{
				{
					TraceId:    traceID,
					SpanId:     childSpanID,
					TraceState: "linked=1",
					Attributes: []*commonpb.KeyValue{
						{Key: "link.kind", Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: "follows"}}},
					},
				},
			},
			Status: &tracepb.Status{Code: tracepb.Status_STATUS_CODE_ERROR, Message: "checkout failed"},
		},
		{
			TraceId:           traceID,
			SpanId:            childSpanID,
			ParentSpanId:      rootSpanID,
			Name:              "charge card",
			Kind:              tracepb.Span_SPAN_KIND_CLIENT,
			StartTimeUnixNano: start + 5_000_000,
			EndTimeUnixNano:   start + 200_000_000,
			Status:            &tracepb.Status{Code: tracepb.Status_STATUS_CODE_OK},
		},
		{
			TraceId: traceID,
			SpanId:  []byte{0x11, 0x12, 0x13, 0x14, 0x15, 0x16, 0x17, 0x18},
			// A parent that is not in this batch: the orphan the waterfall has
			// to render at root level rather than drop.
			ParentSpanId:      missingParent,
			Name:              "emit receipt",
			Kind:              tracepb.Span_SPAN_KIND_PRODUCER,
			StartTimeUnixNano: start + 30_000_000,
			EndTimeUnixNano:   start + 40_000_000,
			Status:            &tracepb.Status{Code: tracepb.Status_STATUS_CODE_UNSET},
		},
		{
			TraceId:           traceID,
			SpanId:            []byte{0x21, 0x22, 0x23, 0x24, 0x25, 0x26, 0x27, 0x28},
			ParentSpanId:      rootSpanID,
			Name:              "consume receipt",
			Kind:              tracepb.Span_SPAN_KIND_CONSUMER,
			StartTimeUnixNano: start + 45_000_000,
			// Ends before it starts: the clock-skew case Duration has to clamp
			// rather than wrap into a UInt64.
			EndTimeUnixNano: start + 44_000_000,
		},
		{
			TraceId:           traceID,
			SpanId:            []byte{0x31, 0x32, 0x33, 0x34, 0x35, 0x36, 0x37, 0x38},
			ParentSpanId:      rootSpanID,
			Name:              "internal work",
			Kind:              tracepb.Span_SPAN_KIND_INTERNAL,
			StartTimeUnixNano: start + 50_000_000,
			EndTimeUnixNano:   start + 60_000_000,
		},
	}

	request := &coltrace.ExportTraceServiceRequest{
		ResourceSpans: []*tracepb.ResourceSpans{{
			Resource: &resourcepb.Resource{
				Attributes: []*commonpb.KeyValue{
					{Key: "service.name", Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: "checkout"}}},
					{Key: "deployment.generation", Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_IntValue{IntValue: 41}}},
				},
			},
			SchemaUrl: "https://opentelemetry.io/schemas/1.27.0",
			ScopeSpans: []*tracepb.ScopeSpans{{
				Scope: &commonpb.InstrumentationScope{
					Name:    "checkout.payments",
					Version: "1.4.0",
				},
				SchemaUrl: "https://opentelemetry.io/schemas/1.27.0",
				Spans:     spans,
			}},
		}},
	}

	writePair("otlp-traces-kitchen-sink", request)
}
