<?php

namespace App\Services\Autofix;

/**
 * One file's worth of a unified diff.
 *
 * Both sides of the path are kept, renames included, because the validator has
 * to hold the denylist against the path a change comes from as well as the one
 * it goes to — moving `.env` somewhere harmless is still touching `.env`.
 */
class DiffFile
{
    /**
     * @param  list<DiffHunk>  $hunks
     */
    public function __construct(
        public readonly ?string $oldPath,
        public readonly ?string $newPath,
        public readonly bool $isNew,
        public readonly bool $isDeleted,
        public readonly bool $isRename,
        public readonly bool $isBinary,
        public readonly ?string $mode,
        public readonly array $hunks,
    ) {}

    /**
     * Every path the change touches, on either side.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = array_filter([$this->oldPath, $this->newPath], fn (?string $path): bool => $path !== null && $path !== '');

        return array_values(array_unique($paths));
    }

    /**
     * How many lines the file's hunks add or remove.
     */
    public function changedLines(): int
    {
        return array_sum(array_map(fn (DiffHunk $hunk): int => $hunk->changedLines(), $this->hunks));
    }
}
