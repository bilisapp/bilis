<?php

namespace App\Models\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as BaseClient;
use Laravel\Passport\Scope;

/**
 * The OAuth client an AI assistant registers for itself.
 *
 * Bilis has no first-party OAuth client of its own: every client reaching the
 * MCP server registered dynamically moments earlier and is, by definition, a
 * third party. None of them may skip the approval screen.
 */
class Client extends BaseClient
{
    /**
     * Determine if the client should skip the authorization prompt.
     *
     * @param  Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return false;
    }
}
