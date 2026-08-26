<?php

namespace App\Services\Docs;

/**
 * The rendered HTML of a documentation page plus its "On this page" entries.
 */
class RenderedDoc
{
    /**
     * @param  array<int, array{id: string, title: string, level: int}>  $tableOfContents
     */
    public function __construct(
        public readonly string $html,
        public readonly array $tableOfContents,
    ) {}
}
