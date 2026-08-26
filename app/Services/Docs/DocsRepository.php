<?php

namespace App\Services\Docs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use SplFileInfo;

/**
 * Reads the documentation tree from `resources/docs`.
 *
 * A page is `{section}/{page}.md` with a small front matter block; a section
 * describes itself in `_section.md`. Rendered HTML is cached against the file
 * modification time, so an edit shows up on the next request in development
 * without any cache clearing.
 */
class DocsRepository
{
    /**
     * How long a rendered page or a nav tree stays cached.
     */
    private const CACHE_TTL_SECONDS = 3600;

    private readonly string $directory;

    public function __construct(
        private readonly DocsRenderer $renderer,
        ?string $directory = null,
    ) {
        $this->directory = $directory ?? resource_path('docs');
    }

    /**
     * Every section, in nav order, with its pages in nav order.
     *
     * @return array<int, DocsSection>
     */
    public function sections(): array
    {
        /** @var array<int, DocsSection> $sections */
        $sections = Cache::remember(
            'docs:nav:'.$this->signature(),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->scan(),
        );

        return $sections;
    }

    /**
     * Find a page by its section and page slug.
     */
    public function find(string $section, string $slug): ?DocsPage
    {
        foreach ($this->sections() as $candidate) {
            if ($candidate->slug !== $section) {
                continue;
            }

            foreach ($candidate->pages as $page) {
                if ($page->slug === $slug) {
                    return $page;
                }
            }
        }

        return null;
    }

    /**
     * The first page of the first section — where `/docs` lands.
     */
    public function firstPage(): ?DocsPage
    {
        foreach ($this->sections() as $section) {
            foreach ($section->pages as $page) {
                return $page;
            }
        }

        return null;
    }

    /**
     * The page before and after the given one, in nav order.
     *
     * @return array{previous: ?DocsPage, next: ?DocsPage}
     */
    public function neighbours(DocsPage $current): array
    {
        $flat = [];

        foreach ($this->sections() as $section) {
            foreach ($section->pages as $page) {
                $flat[] = $page;
            }
        }

        foreach ($flat as $index => $page) {
            if ($page->is($current)) {
                return [
                    'previous' => $flat[$index - 1] ?? null,
                    'next' => $flat[$index + 1] ?? null,
                ];
            }
        }

        return ['previous' => null, 'next' => null];
    }

    /**
     * Render a page to HTML, caching against the file's modification time.
     */
    public function render(DocsPage $page): RenderedDoc
    {
        $key = 'docs:page:'.md5($page->path.':'.(File::lastModified($page->path) ?: 0));

        /** @var RenderedDoc $rendered */
        $rendered = Cache::remember(
            $key,
            self::CACHE_TTL_SECONDS,
            fn (): RenderedDoc => $this->renderer->render(FrontMatter::parse(File::get($page->path))['body']),
        );

        return $rendered;
    }

    /**
     * Walk the docs directory and build the section tree.
     *
     * @return array<int, DocsSection>
     */
    private function scan(): array
    {
        if (! File::isDirectory($this->directory)) {
            return [];
        }

        $sections = [];

        foreach (File::directories($this->directory) as $path) {
            $slug = basename($path);
            $meta = $this->attributes($path.DIRECTORY_SEPARATOR.'_section.md');

            $sections[] = new DocsSection(
                slug: $slug,
                title: $meta['title'] ?? str_replace('-', ' ', ucfirst($slug)),
                order: (int) ($meta['order'] ?? 999),
                pages: $this->pages($path, $slug),
            );
        }

        usort($sections, fn (DocsSection $a, DocsSection $b): int => [$a->order, $a->title] <=> [$b->order, $b->title]);

        return $sections;
    }

    /**
     * The pages of one section directory, in nav order.
     *
     * @return array<int, DocsPage>
     */
    private function pages(string $directory, string $section): array
    {
        $pages = [];

        foreach (File::files($directory) as $file) {
            if (! $this->isPage($file)) {
                continue;
            }

            $slug = $file->getBasename('.md');
            $attributes = $this->attributes($file->getPathname());

            $pages[] = new DocsPage(
                section: $section,
                slug: $slug,
                title: $attributes['title'] ?? str_replace('-', ' ', ucfirst($slug)),
                description: $attributes['description'] ?? null,
                order: (int) ($attributes['order'] ?? 999),
                path: $file->getPathname(),
            );
        }

        usort($pages, fn (DocsPage $a, DocsPage $b): int => [$a->order, $a->title] <=> [$b->order, $b->title]);

        return $pages;
    }

    /**
     * Whether a file is a documentation page rather than section metadata.
     */
    private function isPage(SplFileInfo $file): bool
    {
        return $file->getExtension() === 'md' && ! str_starts_with($file->getBasename(), '_');
    }

    /**
     * The front matter of a file, or an empty set when it does not exist.
     *
     * @return array<string, string>
     */
    private function attributes(string $path): array
    {
        return File::exists($path)
            ? FrontMatter::parse(File::get($path))['attributes']
            : [];
    }

    /**
     * A fingerprint of the docs tree, so the nav cache follows edits.
     */
    private function signature(): string
    {
        if (! File::isDirectory($this->directory)) {
            return 'empty';
        }

        $parts = [];

        foreach (File::allFiles($this->directory) as $file) {
            $parts[] = $file->getPathname().':'.$file->getMTime();
        }

        sort($parts);

        return md5(implode('|', $parts));
    }
}
