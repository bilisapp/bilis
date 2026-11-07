<?php

namespace App\Services\Tools;

/**
 * One fetched page: the raw HTML exactly as the server sent it, before any script ran.
 */
final readonly class FetchedPage
{
    /**
     * @param  list<array{url: string, status: int}>  $redirects
     */
    public function __construct(
        public string $requestedUrl,
        public string $finalUrl,
        public int $status,
        public string $contentType,
        public string $robotsHeader,
        public array $redirects,
        public string $html,
        public int $bytes,
        public bool $truncated,
        public int $elapsedMs,
    ) {}

    public function isHtml(): bool
    {
        return $this->contentType === ''
            || str_contains(strtolower($this->contentType), 'html');
    }
}
