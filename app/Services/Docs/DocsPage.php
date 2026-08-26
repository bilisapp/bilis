<?php

namespace App\Services\Docs;

/**
 * One markdown file under `resources/docs`, described by its front matter.
 */
class DocsPage
{
    public function __construct(
        public readonly string $section,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $description,
        public readonly int $order,
        public readonly string $path,
    ) {}

    /**
     * The public URL this page is served from.
     */
    public function url(): string
    {
        return route('docs.show', ['section' => $this->section, 'page' => $this->slug]);
    }

    /**
     * Whether this page is the one currently being rendered.
     */
    public function is(?self $other): bool
    {
        return $other !== null && $other->section === $this->section && $other->slug === $this->slug;
    }
}
