<?php

use App\Mcp\Servers\BilisServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
 * The remote MCP server, and the OAuth endpoints an assistant needs to reach
 * it: discovery metadata, dynamic client registration, authorize and token.
 * There is no key to copy — `claude mcp add --transport http bilis <url>/mcp`
 * is the whole setup, and the browser handles the rest.
 */
Mcp::oauthRoutes();

/*
 * Registration is anonymous by design (that is what makes the setup one
 * command), so it is the one OAuth endpoint a stranger can call in a loop.
 * The package registers it unnamed and unthrottled; it gets a limit here.
 */
collect(Route::getRoutes()->getRoutes())
    ->first(fn (Illuminate\Routing\Route $route): bool => $route->uri() === 'oauth/register' && in_array('POST', $route->methods(), true))
    ?->middleware('throttle:oauth-register');

Mcp::web('/mcp', BilisServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
