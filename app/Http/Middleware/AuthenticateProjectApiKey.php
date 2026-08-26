<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\ProjectApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateProjectApiKey
{
    /**
     * The request attribute holding the resolved project.
     */
    public const PROJECT_ATTRIBUTE = 'project';

    /**
     * The request attribute holding the resolved API key.
     */
    public const API_KEY_ATTRIBUTE = 'project_api_key';

    /**
     * The header carrying the API key when the Authorization header is not used.
     */
    public const KEY_HEADER = 'X-Bilis-Key';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextKey = $this->keyFromRequest($request);

        if ($plainTextKey === null) {
            return $this->unauthorized('API key missing.');
        }

        $apiKey = ProjectApiKey::findByPlainKey($plainTextKey);

        if (! $apiKey?->project) {
            return $this->unauthorized('API key invalid.');
        }

        $request->attributes->set(self::API_KEY_ATTRIBUTE, $apiKey);
        $request->attributes->set(self::PROJECT_ATTRIBUTE, $apiKey->project);

        $apiKey->markAsUsed();

        return $next($request);
    }

    /**
     * Get the project resolved for the given request, if any.
     */
    public static function project(Request $request): ?Project
    {
        $project = $request->attributes->get(self::PROJECT_ATTRIBUTE);

        return $project instanceof Project ? $project : null;
    }

    /**
     * Get the API key resolved for the given request, if any.
     */
    public static function apiKey(Request $request): ?ProjectApiKey
    {
        $apiKey = $request->attributes->get(self::API_KEY_ATTRIBUTE);

        return $apiKey instanceof ProjectApiKey ? $apiKey : null;
    }

    /**
     * Read the plaintext API key from the request headers.
     */
    protected function keyFromRequest(Request $request): ?string
    {
        $key = $request->bearerToken() ?? $request->header(self::KEY_HEADER);

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    /**
     * Build the JSON response returned for missing or invalid keys.
     */
    protected function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
