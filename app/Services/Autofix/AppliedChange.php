<?php

namespace App\Services\Autofix;

/**
 * One file as it looks after a validated diff has been applied to it.
 *
 * `content` is null for a deletion, which the Git Data API expresses as a
 * tree entry with a null sha.
 */
class AppliedChange
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $content,
        public readonly string $mode,
    ) {}

    /**
     * Determine whether the change removes the file.
     */
    public function isDeletion(): bool
    {
        return $this->content === null;
    }
}
