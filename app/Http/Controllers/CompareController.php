<?php

namespace App\Http\Controllers;

use App\Services\Tools\LogVolumeEstimate;
use Illuminate\View\View;

/**
 * Head-to-head pages: Bilis against one named product at a time.
 *
 * Every price on them comes from `LogVolumeEstimate` at the calculator's own
 * defaults, so a comparison page and /tools/log-cost-calculator cannot quote
 * two different bills for the same volume.
 */
class CompareController extends Controller
{
    /**
     * Events per second for the worked examples: a side project, a product, a busy platform.
     */
    private const EXAMPLE_RATES = [50, 1_000, 10_000];

    public function datadog(): View
    {
        return view('marketing.compare.datadog', [
            'examples' => array_map(
                fn (int $rate): LogVolumeEstimate => LogVolumeEstimate::fromInput(['eps' => $rate]),
                self::EXAMPLE_RATES,
            ),
        ]);
    }
}
