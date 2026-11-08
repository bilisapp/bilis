<?php

namespace App\Http\Middleware;

use App\Services\Alerting\AlertBadge;
use App\Services\Issues\IssueBadge;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            /*
             * The navigation has to know about the deployment-wide autofix
             * switch: with it off the endpoints 404, so the rail must not
             * offer a door that does not open.
             */
            'autofix' => ['enabled' => config('autofix.enabled') === true],
            /*
             * The count beside "Issues" in the rail. Deferred so no page waits
             * on ClickHouse to paint, and kept by the client for a minute so
             * moving between pages does not ask again; rescued, so a busy
             * store hides the count instead of failing the page.
             */
            'issueBadge' => Inertia::defer(
                fn (): ?array => $user?->currentTeam ? app(IssueBadge::class)->forTeam($user->currentTeam) : null,
                'nav',
                rescue: true,
            )->once(until: IssueBadge::CACHE_SECONDS),
            /*
             * The count beside "Alerts": series firing now. Same shape as the
             * issue count — deferred in the `nav` group, kept a minute — though
             * it reads only the app database.
             */
            'alertBadge' => Inertia::defer(
                fn (): ?array => $user?->currentTeam ? app(AlertBadge::class)->forTeam($user->currentTeam) : null,
                'nav',
                rescue: true,
            )->once(until: AlertBadge::CACHE_SECONDS),
        ];
    }
}
