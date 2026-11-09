<?php

namespace App\Services\Alerting\Webhooks;

use RuntimeException;

/**
 * A webhook URL Bilis will not send to. The message is one sentence written
 * for the user who configured the channel, safe to show on it as-is.
 */
final class UnsafeWebhookUrl extends RuntimeException {}
