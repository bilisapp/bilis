<?php

namespace App\Services\Docs;

/**
 * A directory under `resources/docs`: a nav group and the pages inside it.
 */
class DocsSection
{
    /**
     * @param  array<int, DocsPage>  $pages
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly int $order,
        public readonly array $pages,
    ) {}
}
