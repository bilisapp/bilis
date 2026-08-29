<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * The public features page.
 */
class FeaturesController extends Controller
{
    public function __invoke(): View
    {
        return view('marketing.features');
    }
}
