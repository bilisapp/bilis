<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateProjectApiKey;
use App\Http\Middleware\AuthenticatePublicKey;
use App\Models\FixJob;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\ProjectRepository;
use App\Services\Autofix\LocalRunDriver;
use App\Services\Autofix\RunDriver;
use App\Services\Autofix\ScalewayRunDriver;
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
        $this->configureAutofixRunner();
    }

    /**
     * Bind the driver that starts Ayos runs.
     *
     * The two implementations differ only in how a run is started and stopped —
     * a child process here, a Serverless Job run in production. Everything
     * downstream is the same code on both sides, which is what makes a local
     * end-to-end test worth running.
     */
    protected function configureAutofixRunner(): void
    {
        $this->app->bind(RunDriver::class, function (): RunDriver {
            return match (config('autofix.runner.driver')) {
                'scaleway' => new ScalewayRunDriver,
                default => new LocalRunDriver,
            };
        });
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
     * Fix jobs reach their team the same way, through their project.
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

        Route::bind('fixJob', function (string $uuid, RouteElement $route): FixJob {
            $teamSlug = $route->parameter('current_team');

            return FixJob::query()
                ->where('uuid', $uuid)
                ->whereHas('project.team', fn ($query) => $query->where('slug', is_string($teamSlug) ? $teamSlug : null))
                ->firstOrFail();
        });

        Route::bind('apiKey', function (string $id, RouteElement $route): ProjectApiKey {
            $project = $route->parameter('project');

            abort_unless($project instanceof Project, 404);

            return $project->apiKeys()->whereKey($id)->firstOrFail();
        });

        /*
         * A project may hold several repositories — one per group of services
         * that share a codebase — so a repository is addressed by id, and
         * resolved through the project so an id from another project (or
         * another team) is a 404 rather than someone else's settings.
         */
        Route::bind('repository', function (string $id, RouteElement $route): ProjectRepository {
            $project = $route->parameter('project');

            abort_unless($project instanceof Project, 404);

            return $project->repositories()->whereKey($id)->firstOrFail();
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
            // A client configured with a DSN sends its public key in a header
            // of its own, so that is read here too: without it every such
            // client would share the one unauthenticated bucket.
            $key = AuthenticateProjectApiKey::keyFromRequest($request)
                ?? AuthenticatePublicKey::keyFromRequest($request);

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
