<?php

namespace App\Mcp\Tools;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('list-teams')]
#[Title('List teams')]
#[Description('List the Bilis teams the signed-in user belongs to, with the slug every other tool accepts as its "team" argument. The team marked "current" is the one used when a tool is called without a team.')]
class ListTeamsTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('You are not signed in. Reconnect to Bilis and approve the sign-in.');
        }

        $currentTeamId = $user->currentTeam?->getKey();

        return Response::json([
            'teams' => $user->teams()
                ->orderBy('name')
                ->get()
                ->map(fn (Team $team): array => [
                    'slug' => $team->slug,
                    'name' => $team->name,
                    'role' => $user->teamRole($team)?->value,
                    'current' => $team->getKey() === $currentTeamId,
                    'projects' => $team->projects()->count(),
                ])
                ->values(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
