<?php

use App\Http\Middleware\AuthenticateProjectApiKey;
use App\Http\Middleware\AuthenticatePublicKey;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleEnvelopeCors;
use App\Http\Middleware\HandleFontPreference;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\VerifyAyosSignature;
use App\Http\Middleware\VerifyGitHubSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * Which proxies are believed is decided per request by the middleware,
         * from `security.trusted_proxies`. Only these four headers are ever
         * honoured; a forwarded prefix or an ELB header is not.
         */
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, TrustProxies::class);

        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->encryptCookies(except: ['appearance', 'font', 'sidebar_state']);

        $middleware->web(append: [
            SecurityHeaders::class,
            HandleAppearance::class,
            HandleFontPreference::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);

        $middleware->api(prepend: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'project.api-key' => AuthenticateProjectApiKey::class,
            'project.public-key' => AuthenticatePublicKey::class,
            'envelope.cors' => HandleEnvelopeCors::class,
            'ayos.signature' => VerifyAyosSignature::class,
            'github.signature' => VerifyGitHubSignature::class,
        ]);

        /*
         * GitHub signs its webhook bodies with the App's webhook secret and
         * carries no session, so the CSRF token it cannot have is not asked
         * for. `github.signature` is the only thing standing in front of the
         * route, and it verifies the raw body before the controller sees it.
         */
        $middleware->preventRequestForgery(except: [
            'webhooks/github',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * `/mcp` is JSON-RPC and never HTML. A client that omits its Accept
         * header would otherwise be redirected to the login page and have to
         * parse it; the challenge it needs is a 401 with `WWW-Authenticate`.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp') || $request->expectsJson(),
        );
    })->create();
