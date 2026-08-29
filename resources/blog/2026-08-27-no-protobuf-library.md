---
title: Bilis decodes OTLP protobuf without a protobuf library
description: Why the binary OTLP endpoint is a few hundred lines of hand-written PHP instead of a composer package or a PECL extension, and what makes that a safe thing to hand-write.
date: 2026-08-27
author: Samuel Vrablik
---

Point an OpenTelemetry collector at Bilis and, unless you have gone out of your
way to configure otherwise, it will send `application/x-protobuf`. That is the
default encoding for OTLP/HTTP, so supporting it is not optional — an endpoint
that only speaks OTLP/JSON is an endpoint most exporters cannot use.

The usual way to accept it is `ext-protobuf` or a generated PHP class library.
Bilis has neither. The binary path is a hand-written wire-format reader and one
decoder that knows the OTLP message shapes: two files, under eight hundred lines
including their comments, no new dependency.

## Why not just install something

Because of what it would cost the person running Bilis. The whole pitch is one
Laravel app and one ClickHouse — a thing you can deploy on a box you already
have. A PECL extension moves a compile step into everyone's Dockerfile and turns
a PHP upgrade into a rebuild. A generated class library brings tens of thousands
of lines of code you did not write into the one place in the application that a
stranger on the internet gets to send bytes to.

For a general protobuf problem that trade is usually worth it. This is not a
general protobuf problem. Bilis reads exactly one message —
`ExportLogsServiceRequest` — and only ever converts it into the same array that
the JSON path already produces. That is a small, closed, permanently stable
target, and the cost of owning it is a few hundred lines.

## The wire format is genuinely small

Protobuf's encoding has five wire types. Two of them are the deprecated group
encoding, which the reader rejects outright. That leaves varints,
length-delimited chunks and the fixed-width types. Every read is bounds-checked
against the end of the reader's window, and anything it cannot represent is
refused rather than guessed at.

The field numbers come from the `.proto` definitions, and the protobuf
compatibility rules are what make hand-writing this safe: a field number never
changes meaning. Each private method in the decoder corresponds to one protobuf
message, takes a reader over that message's bytes, and loops over tags with a
`match` on the field number and a `skip()` default — so an unknown field from a
newer collector is skipped, never an error.

## One rule keeps the rest of the code honest

The decoder's output is the array `json_decode` would have returned for the same
export sent as OTLP/JSON: camelCase keys, nanosecond timestamps as digit
strings, hex trace and span ids, base64 for byte values. Nothing downstream ever
learns which encoding arrived. The mapper that turns an OTLP record into a
ClickHouse row has exactly one input shape.

That is a property you can test rather than a convention you have to remember.
The fixtures are captured from a real Go `otlploghttp` exporter — the same
export in both encodings — and the test asserts the two produce identical rows.
If the decoder ever drifts, it fails against bytes a real collector actually
sent.

## Two things that are easy to get wrong

**Submessages are windows, not copies.** A reader is a range over a shared
buffer, and reading a nested message returns a child reader over a sub-range of
that same buffer. The obvious implementation — `new Reader(substr(...))` at each
level — re-copies everything below every wrapping level, and a body nested deep
enough turns fifteen megabytes of request into hundreds of megabytes of memory.
Only leaf scalars are copied out, once each. PHP strings are copy-on-write, so
sharing the buffer costs a refcount.

**A protobuf string is not a JSON string.** On the wire it is raw bytes with no
UTF-8 guarantee. Carry an ill-formed sequence through to `json_encode` and it
throws, which surfaces as a 503 that fails the entire batch and sends the
exporter into a retry loop — one bad byte costing every good line in the
request. So every field that becomes a JSON string is scrubbed on the way
through. The JSON endpoint never meets this problem, because `json_decode`
rejects bad UTF-8 at the door.

Both of these are the sort of thing a library would have handled for you. That
is the honest cost of the decision, and it is why the hostile cases have tests
and the reasons are written down in the files themselves.

## The escape hatches

`BILIS_OTLP_PROTOBUF=false` turns the binary path off, and the endpoint goes
back to answering `415` while naming the encoding it does support. A body that
will not decode at all is treated the way every other malformed payload is:
skipped and counted, never a `400`. Ingest still does not blame the client.

OTLP over gRPC remains out of scope, and that one is not stubbornness — PHP is a
poor gRPC server, and pretending otherwise would be a worse answer than
documenting the HTTP port clearly.
