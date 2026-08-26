<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Docs\DocsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public documentation site: Blade and CommonMark, no Inertia bundle.
 */
class DocsController extends Controller
{
    /**
     * The placeholder a page uses where a real API key would go. A page that
     * contains it gets the "create a project and key" panel.
     */
    public const API_KEY_PLACEHOLDER = 'bilis_YOUR_API_KEY';

    public function __construct(private readonly DocsRepository $docs) {}

    /**
     * Send `/docs` to the first page of the first section.
     */
    public function index(): RedirectResponse
    {
        $page = $this->docs->firstPage();

        abort_if($page === null, Response::HTTP_NOT_FOUND);

        return redirect()->route('docs.show', ['section' => $page->section, 'page' => $page->slug]);
    }

    /**
     * Render one documentation page.
     */
    public function show(Request $request, string $section, string $page): View
    {
        $current = $this->docs->find($section, $page);

        abort_if($current === null, Response::HTTP_NOT_FOUND);

        $rendered = $this->docs->render($current);

        return view('docs.show', [
            'page' => $current,
            'rendered' => $rendered,
            'sections' => $this->docs->sections(),
            'neighbours' => $this->docs->neighbours($current),
            'needsApiKey' => str_contains($rendered->html, self::API_KEY_PLACEHOLDER),
            'projects' => $this->projects($request),
        ]);
    }

    /**
     * Serve one page as raw markdown, for copying and for machines.
     */
    public function markdown(string $section, string $page): Response
    {
        $current = $this->docs->find($section, $page);

        abort_if($current === null, Response::HTTP_NOT_FOUND);

        return response($this->docs->markdown($current), Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=utf-8',
        ]);
    }

    /**
     * The current team's projects, for the API key panel.
     *
     * Empty for a logged-out visitor, which is every visitor the docs are
     * really written for — the panel then invites them to sign in instead.
     *
     * @return array<int, array{slug: string, name: string}>
     */
    private function projects(Request $request): array
    {
        $team = $request->user()?->currentTeam;

        if ($team === null) {
            return [];
        }

        return $team->projects()
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => ['slug' => $project->slug, 'name' => $project->name])
            ->all();
    }
}
