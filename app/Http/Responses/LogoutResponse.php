<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page after logout (`/`) is Blade, not Inertia, so an Inertia visit must
 * leave with a full page load — a plain redirect would make the client render
 * the landing page's HTML inside its error modal.
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : Inertia::location(Fortify::redirects('logout', route('home')));
    }
}
