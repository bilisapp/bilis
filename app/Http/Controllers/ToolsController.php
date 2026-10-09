<?php

namespace App\Http\Controllers;

use App\Services\Tools\LogLevelCatalog;
use App\Services\Tools\LogVolumeEstimate;
use App\Services\Tools\TimestampConversion;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\Traceparent;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The free public tools under /tools.
 *
 * Every tool is a plain GET form computed here, so it works without
 * JavaScript and a result can be linked to. `resources/js/marketing/tool-form.ts`
 * only re-submits the form as the visitor types and swaps the result in.
 * Bad input is never a 422: each service clamps or explains instead.
 */
class ToolsController extends Controller
{
    public function index(): View
    {
        return view('marketing.tools.index', ['tools' => ToolCatalog::all()]);
    }

    public function logCost(Request $request): View
    {
        return view('marketing.tools.log-cost', [
            'estimate' => LogVolumeEstimate::fromInput($request->query()),
        ]);
    }

    public function logLevels(Request $request, LogLevelCatalog $catalog): View
    {
        return view('marketing.tools.log-levels', [
            'systems' => $catalog->systems(),
            'lookup' => $catalog->lookup($this->text($request, 'level', 64)),
        ]);
    }

    public function traceparent(Request $request): View
    {
        $value = $this->text($request, 'value', 512);

        return view('marketing.tools.traceparent', [
            'value' => $value,
            'parsed' => $request->boolean('generate') || $value === ''
                ? Traceparent::generate()
                : Traceparent::parse($value),
            'generated' => $request->boolean('generate') || $value === '',
        ]);
    }

    public function timestamp(Request $request): View
    {
        return view('marketing.tools.timestamp', [
            'conversion' => TimestampConversion::from(
                $this->text($request, 'value', 64),
                $this->text($request, 'unit', 8) ?: 'auto',
                $this->text($request, 'tz', 64) ?: 'UTC',
            ),
            'timezones' => DateTimeZone::listIdentifiers(),
            'units' => TimestampConversion::UNITS,
        ]);
    }

    /**
     * A query parameter as a bounded string; an array (`?value[]=`) reads as empty.
     */
    private function text(Request $request, string $key, int $limit): string
    {
        $value = $request->query($key);

        return is_string($value) ? mb_substr($value, 0, $limit) : '';
    }
}
