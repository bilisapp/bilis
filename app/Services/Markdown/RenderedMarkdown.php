<?php

namespace App\Services\Markdown;

/**
 * The rendered HTML of one markdown file plus the headings a surface can
 * build an "On this page" list from.
 */
class RenderedMarkdown
{
    /**
     * @param  array<int, array{id: string, title: string, level: int}>  $tableOfContents
     */
    public function __construct(
        public readonly string $html,
        public readonly array $tableOfContents,
    ) {}
}
