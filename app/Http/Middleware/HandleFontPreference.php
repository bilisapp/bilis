<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleFontPreference
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $font = $request->cookie('font');

        View::share('font', $font === 'ibm-plex-mono' ? 'ibm-plex-mono' : 'geist');

        return $next($request);
    }
}
