<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\SaveBrowserOriginsRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProjectBrowserOriginController extends Controller
{
    /**
     * Replace the browser origins allowed to post to this project.
     *
     * The list is stored whole rather than added to: it is short, and someone
     * removing an origin means it should stop working, which a merge would
     * quietly refuse to do.
     */
    public function update(SaveBrowserOriginsRequest $request, string $current_team, Project $project): RedirectResponse
    {
        /** @var array<int, mixed> $submitted */
        $submitted = $request->validated('origins');

        $project->update(['allowed_origins' => Project::normalizeOrigins($submitted)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Browser origins updated.')]);

        return back();
    }
}
