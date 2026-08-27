<?php

namespace App\Services\Autofix;

/**
 * Applies a parsed diff to file content held in memory.
 *
 * Every hunk is context-checked: the lines it claims to have found must be
 * there, byte for byte, or the whole file is a failure to apply. Hunks are
 * allowed to have drifted from the line numbers in their header — the default
 * branch moves while the agent works — so the applier searches outwards from
 * the expected offset for an exact match. It never fuzzes the content itself.
 */
class DiffApplier
{
    /**
     * How far from its stated position a hunk may be found.
     */
    public const SEARCH_WINDOW = 500;

    /**
     * Apply one file's hunks to its current content.
     *
     * `null` in means the file does not exist; `null` out means the diff
     * deletes it.
     *
     * @throws DiffApplyException
     */
    public function apply(?string $original, DiffFile $file): ?string
    {
        $path = $file->newPath ?? $file->oldPath ?? '(unknown)';

        if ($file->isDeleted) {
            if ($original === null) {
                throw new DiffApplyException(sprintf('The diff deletes %s, which does not exist.', $path));
            }

            return null;
        }

        if ($file->isNew && $original !== null && $original !== '') {
            throw new DiffApplyException(sprintf('The diff creates %s, which already exists.', $path));
        }

        if ($file->hunks === []) {
            /*
             * A pure rename or mode change carries no hunks: the content is
             * whatever it already was.
             */
            return $original ?? '';
        }

        $lines = $this->split($original ?? '');
        $endsWithoutNewline = $original !== null && $original !== '' && ! str_ends_with($original, "\n");
        $shift = 0;

        foreach ($file->hunks as $hunk) {
            $old = $hunk->oldLines();
            $expected = max(0, $hunk->oldStart - 1 + $shift);
            $position = $this->locate($lines, $old, $expected);

            if ($position === null) {
                throw new DiffApplyException(sprintf(
                    'Hunk @@ -%d,%d @@ of %s does not match the current content.',
                    $hunk->oldStart,
                    $hunk->oldCount,
                    $path,
                ));
            }

            $new = $hunk->newLines();
            $touchesEnd = $position + count($old) === count($lines);

            array_splice($lines, $position, count($old), $new);

            $shift += count($new) - count($old);

            if ($touchesEnd) {
                $endsWithoutNewline = $hunk->newSideEndsWithoutNewline();
            }
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines).($endsWithoutNewline ? '' : "\n");
    }

    /**
     * Find where a hunk's old side sits, nearest to where it says it does.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $old
     */
    protected function locate(array $lines, array $old, int $expected): ?int
    {
        if ($old === []) {
            return min($expected, count($lines));
        }

        $last = count($lines) - count($old);

        if ($last < 0) {
            return null;
        }

        for ($distance = 0; $distance <= self::SEARCH_WINDOW; $distance++) {
            foreach ($distance === 0 ? [$expected] : [$expected - $distance, $expected + $distance] as $candidate) {
                if ($candidate < 0 || $candidate > $last) {
                    continue;
                }

                if ($this->matches($lines, $old, $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Determine whether the old side sits at exactly this offset.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $old
     */
    protected function matches(array $lines, array $old, int $offset): bool
    {
        foreach ($old as $index => $line) {
            if (($lines[$offset + $index] ?? null) !== $line) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split content into lines, dropping the empty tail a final newline makes.
     *
     * @return list<string>
     */
    protected function split(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $lines = explode("\n", $content);

        if ($lines[count($lines) - 1] === '') {
            array_pop($lines);
        }

        return $lines;
    }
}
