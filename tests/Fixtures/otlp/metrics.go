// Metric fixtures, generated the same way the log and trace ones are: a real
// Go exporter posting to a local server for the wire capture, and the OTLP
// proto types directly for the shapes an SDK cannot be made to emit on cue.
//
// `otlp-metrics-export.bin` is what go.opentelemetry.io/otel/exporters/otlp/
// otlpmetric/otlpmetrichttp actually sends: a counter, an up-down counter, a
// gauge, an explicit-bucket histogram and a base-2 exponential histogram, all
// cumulative (the SDK default). It is not byte-stable -- the SDK stamps points
// with wall-clock time.
//
// `otlp-metrics-kitchen-sink.bin` reaches everything else: a summary, delta
// temporality, int and double values, exemplars with and without ids, metric
// metadata, scope attributes and schema urls, a negative exponential scale
// with negative buckets, the no-recorded-value flag, and two points the mapper
// must reject (a NaN value and a missing timestamp).
package main

import (
	"context"
	"fmt"
	"io"
	"math"
	"net/http"
	"net/http/httptest"
	"strings"

	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/exporters/otlp/otlpmetric/otlpmetrichttp"
	"go.opentelemetry.io/otel/metric"
	sdkmetric "go.opentelemetry.io/otel/sdk/metric"
	"go.opentelemetry.io/otel/sdk/resource"
	colmetrics "go.opentelemetry.io/proto/otlp/collector/metrics/v1"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
	"google.golang.org/protobuf/proto"
)

func metricFixtures() {
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

	exporter, err := otlpmetrichttp.New(context.Background(),
		otlpmetrichttp.WithEndpoint(strings.TrimPrefix(server.URL, "http://")),
		otlpmetrichttp.WithInsecure(),
		otlpmetrichttp.WithURLPath("/api/v1/metrics"),
	)
	if err != nil {
		panic(err)
	}

	res, _ := resource.Merge(resource.Empty(), resource.NewSchemaless(
		attribute.String("service.name", "checkout"),
		attribute.Int("deployment.generation", 41),
		attribute.Bool("service.canary", true),
	))

	reader := sdkmetric.NewPeriodicReader(exporter)
	provider := sdkmetric.NewMeterProvider(
		sdkmetric.WithResource(res),
		sdkmetric.WithReader(reader),
		sdkmetric.WithView(sdkmetric.NewView(
			sdkmetric.Instrument{Name: "checkout.payload.size"},
			sdkmetric.Stream{Aggregation: sdkmetric.AggregationBase2ExponentialHistogram{MaxSize: 160, MaxScale: 20}},
		)),
	)

	meter := provider.Meter("checkout.payments", metric.WithInstrumentationVersion("1.4.0"))
	ctx := context.Background()

	requests, _ := meter.Int64Counter("http.server.requests", metric.WithUnit("{request}"), metric.WithDescription("Requests served."))
	requests.Add(ctx, 41, metric.WithAttributes(attribute.String("http.route", "/checkout"), attribute.Int("http.status_code", 200)))
	requests.Add(ctx, 3, metric.WithAttributes(attribute.String("http.route", "/checkout"), attribute.Int("http.status_code", 500)))

	inflight, _ := meter.Int64UpDownCounter("http.server.active_requests", metric.WithUnit("{request}"))
	inflight.Add(ctx, 5)
	inflight.Add(ctx, -2)

	meter.Float64ObservableGauge("process.memory.usage.ratio",
		metric.WithUnit("1"),
		metric.WithFloat64Callback(func(_ context.Context, o metric.Float64Observer) error {
			o.Observe(0.625, metric.WithAttributes(attribute.String("pool", "heap")))

			return nil
		}),
	)

	duration, _ := meter.Float64Histogram("http.server.request.duration",
		metric.WithUnit("s"),
		metric.WithExplicitBucketBoundaries(0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1),
	)
	for _, seconds := range []float64{0.004, 0.012, 0.03, 0.03, 0.2, 0.7, 1.5} {
		duration.Record(ctx, seconds, metric.WithAttributes(attribute.String("http.route", "/checkout")))
	}

	payload, _ := meter.Int64Histogram("checkout.payload.size", metric.WithUnit("By"))
	for _, size := range []int64{512, 1024, 1500, 4096, 70000} {
		payload.Record(ctx, size)
	}

	if err := provider.Shutdown(context.Background()); err != nil {
		panic(err)
	}

	fmt.Println("metrics content-type:", headers.Get("Content-Type"))
	fmt.Println("metrics bytes:", len(body))

	request := &colmetrics.ExportMetricsServiceRequest{}
	if err := proto.Unmarshal(body, request); err != nil {
		panic(err)
	}

	writePair("otlp-metrics-export", request)
	writePair("otlp-metrics-kitchen-sink", metricKitchenSink())
}

func metricKitchenSink() *colmetrics.ExportMetricsServiceRequest {
	const start = uint64(1756211000000000000)
	const t1 = uint64(1756211400123456789)
	const t2 = uint64(1756211460000000000)

	str := func(key, value string) *commonpb.KeyValue {
		return &commonpb.KeyValue{Key: key, Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_StringValue{StringValue: value}}}
	}
	integer := func(key string, value int64) *commonpb.KeyValue {
		return &commonpb.KeyValue{Key: key, Value: &commonpb.AnyValue{Value: &commonpb.AnyValue_IntValue{IntValue: value}}}
	}

	traceID := []byte{0x5b, 0x8e, 0xff, 0xf7, 0x98, 0x03, 0x81, 0x03, 0xd2, 0x69, 0xb6, 0x33, 0x81, 0x3f, 0xc6, 0x0c}
	spanID := []byte{0xee, 0xe1, 0x9b, 0x7e, 0xc3, 0xc1, 0xb1, 0x74}

	sum := 12.5
	min := 0.25
	max := 7.0
	expSum := -3.5
	expMin := -4.0
	expMax := 1.0

	return &colmetrics.ExportMetricsServiceRequest{
		ResourceMetrics: []*metricspb.ResourceMetrics{{
			Resource: &resourcepb.Resource{Attributes: []*commonpb.KeyValue{
				str("service.name", "billing"),
				str("host.name", "worker-3"),
			}},
			SchemaUrl: "https://opentelemetry.io/schemas/1.26.0",
			ScopeMetrics: []*metricspb.ScopeMetrics{{
				Scope: &commonpb.InstrumentationScope{
					Name:       "billing.jobs",
					Version:    "2.0.1",
					Attributes: []*commonpb.KeyValue{str("scope.kind", "worker")},
				},
				SchemaUrl: "https://opentelemetry.io/schemas/1.26.0",
				Metrics: []*metricspb.Metric{
					{
						Name:        "queue.depth",
						Description: "Jobs waiting.",
						Unit:        "{job}",
						Metadata:    []*commonpb.KeyValue{str("source", "horizon")},
						Data: &metricspb.Metric_Gauge{Gauge: &metricspb.Gauge{DataPoints: []*metricspb.NumberDataPoint{
							{
								Attributes:   []*commonpb.KeyValue{str("queue", "default")},
								TimeUnixNano: t1,
								Value:        &metricspb.NumberDataPoint_AsInt{AsInt: -7},
								Exemplars: []*metricspb.Exemplar{{
									FilteredAttributes: []*commonpb.KeyValue{str("job", "SendInvoice")},
									TimeUnixNano:       t1,
									Value:              &metricspb.Exemplar_AsInt{AsInt: 9},
									SpanId:             spanID,
									TraceId:            traceID,
								}},
							},
							{
								Attributes:   []*commonpb.KeyValue{str("queue", "mail")},
								TimeUnixNano: t2,
								Value:        &metricspb.NumberDataPoint_AsDouble{AsDouble: 2.75},
								Flags:        1,
							},
							// Rejected: NaN cannot be stored and would fail the batch's JSON.
							{TimeUnixNano: t2, Value: &metricspb.NumberDataPoint_AsDouble{AsDouble: math.NaN()}},
							// Rejected: a point with no time is not a measurement.
							{Value: &metricspb.NumberDataPoint_AsDouble{AsDouble: 1}},
						}}},
					},
					{
						Name: "invoices.sent",
						Unit: "{invoice}",
						Data: &metricspb.Metric_Sum{Sum: &metricspb.Sum{
							AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_DELTA,
							IsMonotonic:            true,
							DataPoints: []*metricspb.NumberDataPoint{{
								Attributes:        []*commonpb.KeyValue{integer("tenant", 12)},
								StartTimeUnixNano: t1,
								TimeUnixNano:      t2,
								Value:             &metricspb.NumberDataPoint_AsInt{AsInt: 120},
								Exemplars:         []*metricspb.Exemplar{{TimeUnixNano: t2, Value: &metricspb.Exemplar_AsDouble{AsDouble: 1}}},
							}},
						}},
					},
					{
						Name: "ledger.balance",
						Unit: "EUR",
						Data: &metricspb.Metric_Sum{Sum: &metricspb.Sum{
							AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE,
							DataPoints: []*metricspb.NumberDataPoint{{
								StartTimeUnixNano: start,
								TimeUnixNano:      t1,
								Value:             &metricspb.NumberDataPoint_AsDouble{AsDouble: -1250.5},
							}},
						}},
					},
					{
						Name: "job.duration",
						Unit: "s",
						Data: &metricspb.Metric_Histogram{Histogram: &metricspb.Histogram{
							AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_DELTA,
							DataPoints: []*metricspb.HistogramDataPoint{
								{
									Attributes:        []*commonpb.KeyValue{str("job", "SendInvoice")},
									StartTimeUnixNano: t1,
									TimeUnixNano:      t2,
									Count:             6,
									Sum:               &sum,
									Min:               &min,
									Max:               &max,
									BucketCounts:      []uint64{1, 2, 0, 3},
									ExplicitBounds:    []float64{0.5, 1, 5},
									Exemplars: []*metricspb.Exemplar{{
										TimeUnixNano: t2,
										Value:        &metricspb.Exemplar_AsDouble{AsDouble: 7},
										TraceId:      traceID,
										SpanId:       spanID,
									}},
								},
								// A point with no recorded value: no sum, no buckets.
								{StartTimeUnixNano: t1, TimeUnixNano: t2, Flags: 1},
							},
						}},
					},
					{
						Name: "payload.delta",
						Unit: "By",
						Data: &metricspb.Metric_ExponentialHistogram{ExponentialHistogram: &metricspb.ExponentialHistogram{
							AggregationTemporality: metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE,
							DataPoints: []*metricspb.ExponentialHistogramDataPoint{{
								Attributes:        []*commonpb.KeyValue{str("direction", "in")},
								StartTimeUnixNano: start,
								TimeUnixNano:      t1,
								Count:             9,
								Sum:               &expSum,
								Min:               &expMin,
								Max:               &expMax,
								Scale:             -1,
								ZeroCount:         2,
								ZeroThreshold:     0.001,
								Positive:          &metricspb.ExponentialHistogramDataPoint_Buckets{Offset: -2, BucketCounts: []uint64{1, 0, 2}},
								Negative:          &metricspb.ExponentialHistogramDataPoint_Buckets{Offset: 1, BucketCounts: []uint64{3, 1}},
								Exemplars:         []*metricspb.Exemplar{{TimeUnixNano: t1, Value: &metricspb.Exemplar_AsDouble{AsDouble: -4}}},
							}},
						}},
					},
					{
						Name:        "rpc.latency",
						Description: "Client-side quantiles from a Prometheus summary.",
						Unit:        "ms",
						Data: &metricspb.Metric_Summary{Summary: &metricspb.Summary{DataPoints: []*metricspb.SummaryDataPoint{{
							Attributes:        []*commonpb.KeyValue{str("rpc.method", "Charge")},
							StartTimeUnixNano: start,
							TimeUnixNano:      t1,
							Count:             1000,
							Sum:               48250.5,
							QuantileValues: []*metricspb.SummaryDataPoint_ValueAtQuantile{
								{Quantile: 0, Value: 3},
								{Quantile: 0.5, Value: 41.5},
								{Quantile: 0.99, Value: 220},
								{Quantile: 1, Value: 512},
							},
						}}}},
					},
				},
			}},
		}},
	}
}
