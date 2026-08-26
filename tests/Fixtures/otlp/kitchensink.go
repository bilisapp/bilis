package main

import (
	collogs "go.opentelemetry.io/proto/otlp/collector/logs/v1"
	commonv1 "go.opentelemetry.io/proto/otlp/common/v1"
	logsv1 "go.opentelemetry.io/proto/otlp/logs/v1"
	resourcev1 "go.opentelemetry.io/proto/otlp/resource/v1"
	"google.golang.org/protobuf/reflect/protoreflect"
)

func str(v string) *commonv1.AnyValue {
	return &commonv1.AnyValue{Value: &commonv1.AnyValue_StringValue{StringValue: v}}
}

func intv(v int64) *commonv1.AnyValue {
	return &commonv1.AnyValue{Value: &commonv1.AnyValue_IntValue{IntValue: v}}
}

func bytesv(v []byte) *commonv1.AnyValue {
	return &commonv1.AnyValue{Value: &commonv1.AnyValue_BytesValue{BytesValue: v}}
}

func arrayv(values ...*commonv1.AnyValue) *commonv1.AnyValue {
	return &commonv1.AnyValue{Value: &commonv1.AnyValue_ArrayValue{
		ArrayValue: &commonv1.ArrayValue{Values: values},
	}}
}

func kvlistv(values ...*commonv1.KeyValue) *commonv1.AnyValue {
	return &commonv1.AnyValue{Value: &commonv1.AnyValue_KvlistValue{
		KvlistValue: &commonv1.KeyValueList{Values: values},
	}}
}

// kitchenSink covers what the Go SDK cannot produce through its own API —
// kvlist and bytes values, nested arrays, schema URLs, an out-of-range
// severity number, a record carrying only an observed time, a body that is not
// a string — plus an unknown field, which a decoder must skip rather than
// choke on.
func kitchenSink() {
	request := &collogs.ExportLogsServiceRequest{
		ResourceLogs: []*logsv1.ResourceLogs{
			{
				SchemaUrl: "https://opentelemetry.io/schemas/1.30.0",
				Resource: &resourcev1.Resource{
					Attributes: []*commonv1.KeyValue{
						{Key: "service.name", Value: str("billing")},
						{Key: "host.ids", Value: arrayv(intv(7), intv(9))},
					},
				},
				ScopeLogs: []*logsv1.ScopeLogs{
					{
						SchemaUrl: "https://opentelemetry.io/schemas/1.31.0",
						Scope: &commonv1.InstrumentationScope{
							Name:    "billing.invoices",
							Version: "2.0.1",
							Attributes: []*commonv1.KeyValue{
								{Key: "scope.owner", Value: str("payments")},
							},
						},
						LogRecords: []*logsv1.LogRecord{
							{
								// Structured body, kvlist and bytes attributes, unicode.
								ObservedTimeUnixNano: 1756211400000000000,
								SeverityNumber:       logsv1.SeverityNumber_SEVERITY_NUMBER_INFO2,
								SeverityText:         "NOTICE",
								Body: kvlistv(
									&commonv1.KeyValue{Key: "event", Value: str("invoice.sent")},
									&commonv1.KeyValue{Key: "invoice", Value: intv(9021)},
								),
								Attributes: []*commonv1.KeyValue{
									{Key: "issuer", Value: kvlistv(
										&commonv1.KeyValue{Key: "code", Value: str("51")},
										&commonv1.KeyValue{Key: "latency_ms", Value: intv(240)},
									)},
									{Key: "digest", Value: bytesv([]byte{0xDE, 0xAD, 0xBE, 0xEF})},
									{Key: "matrix", Value: arrayv(arrayv(intv(1), intv(2)), arrayv(intv(3)))},
									{Key: "note", Value: str("faktúra — zaplatená ✅")},
								},
							},
							{
								// A severity number outside 1–24, and no body at all.
								TimeUnixNano:   1756211401000000000,
								SeverityNumber: logsv1.SeverityNumber(99),
								SeverityText:   "SPICY",
							},
						},
					},
				},
			},
		},
	}

	// An unknown field (field 4095, length-delimited) on the request itself.
	unknown := []byte{0xFA, 0xFF, 0x03, 0x04, 'x', 'y', 'z', 'w'}
	request.ProtoReflect().SetUnknown(protoreflect.RawFields(unknown))

	writePair("otlp-logs-kitchen-sink", request)
}
