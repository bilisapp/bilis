package main

import (
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"

	collogs "go.opentelemetry.io/proto/otlp/collector/logs/v1"
	"google.golang.org/protobuf/encoding/protojson"
	"google.golang.org/protobuf/proto"
)

// writePair writes the protobuf body and its OTLP/JSON equivalent. protojson
// base64s bytes fields; the OTLP JSON spec wants hex for trace and span ids,
// which is what a real JSON sender emits, so they are converted here.
func writePair(name string, request *collogs.ExportLogsServiceRequest) {
	raw, err := proto.Marshal(request)
	if err != nil {
		panic(err)
	}

	jsonBytes, err := protojson.MarshalOptions{UseEnumNumbers: true}.Marshal(request)
	if err != nil {
		panic(err)
	}

	var tree any
	if err := json.Unmarshal(jsonBytes, &tree); err != nil {
		panic(err)
	}
	hexIds(tree)

	pretty, err := json.MarshalIndent(tree, "", "    ")
	if err != nil {
		panic(err)
	}

	os.WriteFile(name+".bin", raw, 0o644)
	os.WriteFile(name+".json", append(pretty, '\n'), 0o644)
}

func hexIds(node any) {
	switch value := node.(type) {
	case map[string]any:
		for key, child := range value {
			if (key == "traceId" || key == "spanId") && child != nil {
				if encoded, ok := child.(string); ok {
					if decoded, err := base64.StdEncoding.DecodeString(encoded); err == nil {
						value[key] = hex.EncodeToString(decoded)

						continue
					}
				}
			}

			hexIds(child)
		}
	case []any:
		for _, child := range value {
			hexIds(child)
		}
	}
}
