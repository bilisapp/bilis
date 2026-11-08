<?php

namespace App\Services\Alerting\Sources;

use RuntimeException;

/**
 * The source could not answer (storage down, timed out, malformed query).
 * The evaluator keeps every instance's state and marks the rule's health `err`.
 */
class AlertSourceUnavailable extends RuntimeException {}
