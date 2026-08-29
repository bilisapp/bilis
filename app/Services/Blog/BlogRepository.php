<?php

namespace App\Services\Blog;

use App\Services\Markdown\FrontMatter;
use App\Services\Markdown\MarkdownRenderer;
use App\Services\Markdown\RenderedMarkdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Reads the blog from `resources/blog`.
 *
 * One markdown file per post, named `{yyyy-mm-dd}-{slug}.md`, with the same
 * small front matter block the docs use — plus an optional `draft: true`,
 * which keeps a file in the repository and off the site. Posts are reviewed
 * in pull requests and deploy with the application: there is no posts table
 * and no admin.
 */
class BlogRepository
{
    private const CACHE_TTL_SECONDS = 3600;

    private readonly string $directory;

    public function __construct(
        private readonly MarkdownRenderer $renderer,
        ?string $directory = null,
    ) {
        $this->directory = $directory ?? resource_path('blog');
    }

    /**
     * Every published post, newest first.
     *
     * @return array<int, BlogPost>
     */
    public function posts(): array
    {
        /*
         * Cached as plain arrays rather than serialized objects, for the same
         * reason the docs nav is: the database cache store refuses to
         * unserialize objects, and primitives survive class renames.
         */

        /** @var array<int, array<string, mixed>> $posts */
        $posts = Cache::remember(
            'blog:index:v2:'.$this->signature(),
            self::CACHE_TTL_SECONDS,
            fn (): array => array_map(
                fn (BlogPost $post): array => [
                    'slug' => $post->slug,
                    'title' => $post->title,
                    'description' => $post->description,
                    'date' => $post->date->toDateString(),
                    'author' => $post->author,
                    'path' => $post->path,
                ],
                $this->scan(),
            ),
        );

        return array_map(
            fn (array $post): BlogPost => new BlogPost(
                slug: (string) $post['slug'],
                title: (string) $post['title'],
                description: $post['description'] !== null ? (string) $post['description'] : null,
                date: Carbon::parse((string) $post['date']),
                author: $post['author'] !== null ? (string) $post['author'] : null,
                path: (string) $post['path'],
            ),
            $posts,
        );
    }

    /**
     * Find a post by its slug.
     */
    public function find(string $slug): ?BlogPost
    {
        foreach ($this->posts() as $post) {
            if ($post->slug === $slug) {
                return $post;
            }
        }

        return null;
    }

    /**
     * The posts either side of this one in the archive.
     *
     * The list runs newest first, so the neighbours are named by age rather
     * than by direction: "previous" would mean the opposite thing depending
     * on whether you are thinking about the list or about time.
     *
     * @return array{newer: ?BlogPost, older: ?BlogPost}
     */
    public function neighbours(BlogPost $post): array
    {
        $posts = $this->posts();

        foreach ($posts as $index => $candidate) {
            if ($candidate->is($post)) {
                return [
                    'newer' => $posts[$index - 1] ?? null,
                    'older' => $posts[$index + 1] ?? null,
                ];
            }
        }

        return ['newer' => null, 'older' => null];
    }

    /**
     * When the blog as a whole last changed — the newest post's date, or now
     * for an empty blog, because a feed still needs an `updated` element.
     */
    public function updatedAt(): Carbon
    {
        $posts = $this->posts();

        return $posts === [] ? Carbon::now() : $posts[0]->date;
    }

    /**
     * Render a post to HTML, caching against the file's modification time.
     */
    public function render(BlogPost $post): RenderedMarkdown
    {
        $key = 'blog:post:'.md5($post->path.':'.(File::lastModified($post->path) ?: 0));

        /** @var array{html: string, toc: array<int, array{id: string, title: string, level: int}>} $rendered */
        $rendered = Cache::remember(
            $key,
            self::CACHE_TTL_SECONDS,
            function () use ($post): array {
                $doc = $this->renderer->render(FrontMatter::parse(File::get($post->path))['body']);

                return ['html' => $doc->html, 'toc' => $doc->tableOfContents];
            },
        );

        return new RenderedMarkdown(html: $rendered['html'], tableOfContents: $rendered['toc']);
    }

    /**
     * Walk the blog directory, newest post first.
     *
     * @return array<int, BlogPost>
     */
    private function scan(): array
    {
        if (! File::isDirectory($this->directory)) {
            return [];
        }

        $posts = [];

        foreach (File::files($this->directory) as $file) {
            if ($file->getExtension() !== 'md' || str_starts_with($file->getBasename(), '_')) {
                continue;
            }

            $name = $file->getBasename('.md');
            $attributes = FrontMatter::parse(File::get($file->getPathname()))['attributes'];

            /*
             * `draft: true` keeps a post in the repository and out of the
             * site — off the index, out of the feed, and a 404 by URL. A
             * draft is finished by deleting one line, so there is no second
             * place for a post to live while it is being written.
             */
            if ($this->isDraft($attributes)) {
                continue;
            }

            /*
             * The filename carries the date so the directory sorts itself and
             * two posts cannot collide on a slug; front matter may still
             * override it.
             */
            $slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $name) ?? $name;
            $date = $attributes['date'] ?? substr($name, 0, 10);

            $posts[] = new BlogPost(
                slug: $slug,
                title: $attributes['title'] ?? str_replace('-', ' ', ucfirst($slug)),
                description: $attributes['description'] ?? null,
                date: Carbon::parse($date),
                author: $attributes['author'] ?? null,
                path: $file->getPathname(),
            );
        }

        usort($posts, fn (BlogPost $a, BlogPost $b): int => [$b->date->timestamp, $b->slug] <=> [$a->date->timestamp, $a->slug]);

        return $posts;
    }

    /**
     * Whether a post's front matter marks it as a draft.
     *
     * @param  array<string, string>  $attributes
     */
    private function isDraft(array $attributes): bool
    {
        return in_array(strtolower(trim($attributes['draft'] ?? 'false')), ['true', '1', 'yes'], true);
    }

    /**
     * A fingerprint of the blog directory, so the index cache follows edits.
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
