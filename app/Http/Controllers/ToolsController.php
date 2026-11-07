<?php

namespace App\Http\Controllers;

use App\Services\Tools\CrawlerView;
use App\Services\Tools\FetchedPage;
use App\Services\Tools\LogLevelCatalog;
use App\Services\Tools\LogVolumeEstimate;
use App\Services\Tools\PageFetcher;
use App\Services\Tools\PageFetchFailed;
use App\Services\Tools\TimestampConversion;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\Traceparent;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
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
     * Fetch a page without JavaScript and show what a crawler reads.
     *
     * The one tool that reaches out to another server, so it is the one
     * that is metered: a fetch is cached for ten minutes per URL and agent,
     * and uncached fetches are limited per visitor and for the whole site.
     * Running out reads as a sentence in the result, never a 429 page.
     */
    public function crawlerView(Request $request, PageFetcher $fetcher): View
    {
        $url = $this->text($request, 'url', 2048);
        $agent = $this->text($request, 'agent', 16);
        $agent = array_key_exists($agent, PageFetcher::AGENTS) ? $agent : 'googlebot';
        $view = null;
        $error = null;

        if ($url !== '') {
            try {
                $view = CrawlerView::from($this->fetchPage($fetcher, $url, $agent, $request->ip() ?? ''));
            } catch (PageFetchFailed $failed) {
                $error = $failed->getMessage();
            }
        }

        return view('marketing.tools.crawler-view', [
            'url' => $url,
            'agent' => $agent,
            'agents' => PageFetcher::AGENTS,
            'view' => $view,
            'error' => $error,
        ]);
    }

    /**
     * @throws PageFetchFailed
     */
    private function fetchPage(PageFetcher $fetcher, string $url, string $agent, string $ip): FetchedPage
    {
        $key = 'tools:crawler-view:'.hash('sha256', PageFetcher::normalise($url).'|'.$agent);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return new FetchedPage(...$cached);
        }

        $allowed = RateLimiter::attempt('tools:crawler-view:'.$ip, 10, fn (): bool => true, 60)
            && RateLimiter::attempt('tools:crawler-view', 500, fn (): bool => true, 3600);

        if (! $allowed) {
            throw new PageFetchFailed('That is a lot of fetches in a short while. Try again in a minute.');
        }

        $page = $fetcher->fetch($url, $agent);
        Cache::put($key, get_object_vars($page), now()->addMinutes(10));

        return $page;
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
