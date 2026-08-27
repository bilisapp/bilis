<?php

namespace App\Services\Autofix;

/**
 * The result of applying a diff to a commit, ready to be committed back.
 *
 * The head sha and tree sha are pinned here: the publisher builds its tree on
 * the exact commit the validator applied against, so nothing that lands on the
 * default branch in between can silently change what gets pushed.
 */
class AppliedDiff
{
    /**
     * @param  list<AppliedChange>  $changes
     */
    public function __construct(
        public readonly string $headSha,
        public readonly string $treeSha,
        public readonly array $changes,
    ) {}

    /**
     * The paths the change set touches, in order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(fn (AppliedChange $change): string => $change->path, $this->changes);
    }
}
