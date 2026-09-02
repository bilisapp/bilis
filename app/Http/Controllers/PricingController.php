<?php

namespace App\Http\Controllers;

use App\Services\Plans\PlanLimits;
use Illuminate\View\View;

/**
 * The public pricing page.
 *
 * Every number on it comes from `PlanLimits`, so the page and the meters in
 * the app cannot disagree about what Free means.
 */
class PricingController extends Controller
{
    public function __invoke(PlanLimits $limits): View
    {
        return view('marketing.pricing', ['free' => $limits->free()]);
    }
}
