<?php

namespace App\Http\Controllers;

use App\Services\Docs\DocsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public documentation site: Blade and CommonMark, no Inertia bundle.
 */
class DocsController extends Controller
{
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
    public function show(string $section, string $page): View
    {
        $current = $this->docs->find($section, $page);

        abort_if($current === null, Response::HTTP_NOT_FOUND);

        return view('docs.show', [
            'page' => $current,
            'rendered' => $this->docs->render($current),
            'sections' => $this->docs->sections(),
            'neighbours' => $this->docs->neighbours($current),
        ]);
    }
}
