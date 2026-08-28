<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap the Horizon integration.
     */
    public function boot(): void
    {
        parent::boot();

        /*
         * Horizon renders its bundle as one inline `<script type="module">`,
         * which `script-src 'self' 'strict-dynamic'` blocks outright. It will
         * stamp a nonce onto that tag (and its inline `<style>` tags) when it
         * is given one, so hand it the request nonce `SecurityHeaders` already
         * generated. Composed at render time, because the middleware runs
         * after this provider boots.
         */
        View::composer('horizon::layout', function (): void {
            Horizon::cspNonce((string) Vite::cspNonce());
        });
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            if ($user === null) {
                return false;
            }

            $allowedEmails = config('horizon.allowed_emails', []);

            if (! is_array($allowedEmails)) {
                return false;
            }

            return in_array(strtolower($user->email), $allowedEmails, true);
        });
    }
}
