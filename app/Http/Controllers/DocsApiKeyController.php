<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\IssueDocsApiKeyRequest;
use App\Models\ProjectApiKey;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue a project API key straight from a documentation page.
 *
 * The docs are full of `bilis_YOUR_API_KEY`. This turns that placeholder into
 * a real key without a detour through the projects screen: pick an existing
 * project of the current team, or name a new one, and get the plaintext key
 * back once. Answers JSON — the docs are Blade, not Inertia.
 */
class DocsApiKeyController extends Controller
{
    /**
     * The name every key issued from the documentation carries.
     */
    public const KEY_NAME = 'Docs quickstart';

    public function __invoke(IssueDocsApiKeyRequest $request): JsonResponse
    {
        $team = $request->user()?->currentTeam;

        if ($team === null) {
            return response()->json(
                ['message' => __('Create a team before issuing an API key.')],
                Response::HTTP_CONFLICT,
            );
        }

        $slug = $request->validated('project');

        if (is_string($slug) && $slug !== '') {
            /*
             * Scoped to the team, so a slug from another team is a 404 and not
             * a key into someone else's project.
             */
            $project = $team->projects()->where('slug', $slug)->first();

            abort_if($project === null, Response::HTTP_NOT_FOUND);
        } else {
            $project = $team->projects()->create([
                'name' => (string) $request->validated('name'),
            ]);
        }

        $apiKey = ProjectApiKey::generate($project, self::KEY_NAME);

        return response()->json([
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'url' => route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]),
                'created' => $project->wasRecentlyCreated,
            ],
            'key' => $apiKey->plainTextKey,
            'endpoint' => rtrim(url('/'), '/'),
        ], Response::HTTP_CREATED);
    }
}
