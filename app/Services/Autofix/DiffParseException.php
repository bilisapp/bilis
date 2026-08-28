<?php

namespace App\Services\Autofix;

use RuntimeException;

/**
 * A diff that could not be read as a unified diff at all.
 *
 * Always a rejection, never a retry: the bytes will not improve.
 */
class DiffParseException extends RuntimeException {}
