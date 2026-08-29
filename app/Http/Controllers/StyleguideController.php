<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StyleguideController extends Controller
{
    /**
     * Render the public component and brand styleguide.
     *
     * It is the one public Inertia surface — a live gallery of the app's own
     * Vue components cannot be Blade — so it swaps the app shell for a root
     * view that wraps the Inertia mount point in the shared public chrome.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('styleguide/Index')->rootView('styleguide');
    }
}
