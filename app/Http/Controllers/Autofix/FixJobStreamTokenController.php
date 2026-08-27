<?php

namespace App\Http\Controllers\Autofix;

use App\Http\Controllers\Controller;
use App\Models\FixJob;
use App\Services\Autofix\StreamTokenException;
use App\Services\Autofix\StreamTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FixJobStreamTokenController extends Controller
{
    /**
     * Mint a short-lived Ed25519 token for watching one job's live stream.
     *
     * The browser talks to Ayos directly for the stream and nothing else, so
     * this is the only place the two ever meet. A token is refused outright
     * for a finished job: there is nothing left to watch, and the persisted
     * transcript is already on the page.
     */
    public function __invoke(Request $request, StreamTokenIssuer $streamTokens, string $current_team, FixJob $fixJob): JsonResponse
    {
        Gate::authorize('stream', $fixJob);

        $user = $request->user();

        abort_if($user === null, 403);

        try {
            $token = $streamTokens->issue($fixJob, $user);
        } catch (StreamTokenException $exception) {
            report($exception);

            return response()->json(['message' => 'Live streaming is not configured on this instance.'], 503);
        }

        return response()->json([
            'token' => $token['token'],
            'stream_url' => $token['stream_url'],
            'expires_at' => $token['expires_at'],
        ]);
    }
}
