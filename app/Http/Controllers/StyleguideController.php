<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StyleguideController extends Controller
{
    /**
     * Render the internal component and brand styleguide.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('styleguide/Index');
    }
}
