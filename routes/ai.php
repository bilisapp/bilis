<?php

use App\Mcp\Servers\BilisServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * The remote MCP server, and the OAuth endpoints an assistant needs to reach
 * it: discovery metadata, dynamic client registration, authorize and token.
 * There is no key to copy — `claude mcp add --transport http bilis <url>/mcp`
 * is the whole setup, and the browser handles the rest.
 */
Mcp::oauthRoutes();

Mcp::web('/mcp', BilisServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
