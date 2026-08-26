<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
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
