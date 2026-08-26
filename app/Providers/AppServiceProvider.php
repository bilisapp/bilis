<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\ProjectApiKey;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as RouteElement;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
