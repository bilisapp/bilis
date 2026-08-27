<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateProjectApiKey;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\Ingest\IngestRateUsage;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteElement;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRouteBindings();
        $this->configureRateLimiting();
    }

    /**
     * Resolve project and API key route parameters within the current team.
     *
     * Slugs are only unique per team, so a project is always looked up through
     * the team in the route; a project from another team resolves to a 404.
     */
    protected function configureRouteBindings(): void
    {
        Route::bind('project', function (string $slug, RouteElement $route): Project {
            $teamSlug = $route->parameter('current_team');

            return Project::query()
                ->where('slug', $slug)
                ->whereHas('team', fn ($query) => $query->where('slug', is_string($teamSlug) ? $teamSlug : null))
                ->firstOrFail();
        });

        Route::bind('apiKey', function (string $id, RouteElement $route): ProjectApiKey {
            $project = $route->parameter('project');

            abort_unless($project instanceof Project, 404);

            return $project->apiKeys()->whereKey($id)->firstOrFail();
        });
    }

    /**
     * Rate limit the ingest endpoints, per API key.
     *
     * A rejection is a 429 with `Retry-After`, which every OTLP exporter and
     * the Bilis shipper already treat as retryable — the limiter shapes an
     * abusive or runaway client, it does not blame a well-behaved one. Keys
     * are counted individually so one noisy project cannot starve another.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('ingest', function (Request $request): Limit {
            $key = AuthenticateProjectApiKey::keyFromRequest($request);

            // The throttle sorts ahead of the API-key middleware, so the key is
            // read from the request and hashed rather than looked up: a bucket
            // per credential, without a database round trip on every POST.
            if ($key === null) {
                return $this->limit(
                    (int) config('security.ingest_rate_limit_unauthenticated'),
                    IngestRateUsage::bucketForIp($request->ip()),
                );
            }

            return $this->limit(
                (int) config('security.ingest_rate_limit'),
                IngestRateUsage::bucketForKeyHash(ProjectApiKey::hashKey($key)),
            );
        });
    }

    /**
     * A per-minute limit on the given bucket, or none when it is not positive.
     */
    protected function limit(int $perMinute, string $bucket): Limit
    {
        return $perMinute > 0
            ? Limit::perMinute($perMinute)->by($bucket)
            : Limit::none();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
