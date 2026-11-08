<?php

namespace App\Http\Controllers;

use App\Services\Alerting\AlertingHeartbeat;
use Illuminate\Http\JsonResponse;

/**
 * `GET /health/alerting` — is the alerting loop still running?
 *
 * For an external uptime check: no auth, no session, and nothing in the
 * answer but whether `alerts:evaluate` has run in the last three minutes and
 * how long ago. 503 when it has not, so the probe pages someone before a
 * silent scheduler hides a real incident.
 */
class AlertingHealthController extends Controller
{
    /**
     * Report the heartbeat's age.
     */
    public function __invoke(AlertingHeartbeat $heartbeat): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store'];

        if (config('alerting.enabled') !== true) {
            return response()->json(['status' => 'disabled'], 200, $headers);
        }

        $lag = $heartbeat->age();

        if ($lag === null) {
            return response()->json(['status' => 'missing', 'lag' => null], 503, $headers);
        }

        $fresh = $heartbeat->isFresh();

        return response()->json(['status' => $fresh ? 'ok' : 'stale', 'lag' => $lag], $fresh ? 200 : 503, $headers);
    }
}
