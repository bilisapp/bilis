<?php

namespace App\Services\Ingest\Protobuf;

use RuntimeException;

/**
 * A request body that is not a readable protobuf message.
 *
 * Callers turn this into the same answer a malformed JSON body gets — the
 * payload is skipped and counted, never a `4xx` (ingest.md).
 */
class MalformedProtobufException extends RuntimeException {}
