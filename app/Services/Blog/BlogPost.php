<?php

namespace App\Services\Blog;

use Illuminate\Support\Carbon;

/**
 * One markdown file under `resources/blog`, described by its front matter.
 */
class BlogPost
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $description,
        public readonly Carbon $date,
        public readonly ?string $author,
        public readonly string $path,
    ) {}

    /**
     * The public URL this post is served from.
     */
    public function url(): string
    {
        return route('blog.show', ['post' => $this->slug]);
    }

    /**
     * Whether this post is the one currently being rendered.
     */
    public function is(?self $other): bool
    {
        return $other !== null && $other->slug === $this->slug;
    }
}
