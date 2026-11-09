<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Jobs\ProvisionTeamAlerts;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    /**
     * Create a new team and add the user as owner, then queue its default
     * alerts once the team is committed (specs/alerting-defaults.md).
     */
    public function handle(User $user, string $name, bool $isPersonal = false): Team
    {
        return DB::transaction(function () use ($user, $name, $isPersonal) {
            $team = Team::create([
                'name' => $name,
                'is_personal' => $isPersonal,
            ]);

            $membership = $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner,
            ]);

            $user->switchTeam($team);

            ProvisionTeamAlerts::dispatch($team->id)->afterCommit();

            return $team;
        });
    }
}
