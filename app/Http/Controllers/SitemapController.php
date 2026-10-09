<?php

namespace App\Http\Controllers;

use App\Services\Blog\BlogRepository;
use App\Services\Docs\DocsRepository;
use App\Services\Tools\ToolCatalog;
use Illuminate\Http\Response;

/**
 * The sitemap and the robots.txt that points at it.
 *
 * Both are rendered rather than static files so the absolute URLs follow
 * `app.url`: a self-hosted instance advertises its own origin, not bilis.app.
 * Only public, indexable pages are listed — never the app or its auth screens,
 * which carry `noindex` (see `resources/views/app.blade.php`).
 */
class SitemapController extends Controller
{
    public function sitemap(BlogRepository $blog, DocsRepository $docs): Response
    {
        $urls = collect([
            route('home'),
            route('features'),
            route('features.mcp'),
            route('pricing'),
            route('contact.show'),
            route('tools.index'),
            ...array_map(fn (array $tool): string => route($tool['route']), ToolCatalog::all()),
            route('blog.index'),
            ...array_map(fn ($post): string => $post->url(), $blog->posts()),
            route('docs.index'),
        ]);

        foreach ($docs->sections() as $section) {
            foreach ($section->pages as $page) {
                $urls->push($page->url());
            }
        }

        $urls->push(route('terms'), route('privacy'));

        return response()
            ->view('sitemap', ['urls' => $urls->unique()->values()])
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow:',
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode(PHP_EOL, $lines).PHP_EOL)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
