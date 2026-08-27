<?php

namespace App\Services\Autofix;

/**
 * One `@@ -a,b +c,d @@` hunk of a unified diff.
 *
 * Lines keep their sign so the applier can tell context from change without
 * re-parsing: ` ` context, `-` removed, `+` added. A line flagged
 * `no_newline` was followed by git's `\ No newline at end of file` marker.
 */
class DiffHunk
{
    /**
     * @param  list<array{sign: string, text: string, no_newline: bool}>  $lines
     */
    public function __construct(
        public readonly int $oldStart,
        public readonly int $oldCount,
        public readonly int $newStart,
        public readonly int $newCount,
        public readonly array $lines,
    ) {}

    /**
     * The lines the hunk expects to find in the file it is applied to.
     *
     * @return list<string>
     */
    public function oldLines(): array
    {
        $lines = [];

        foreach ($this->lines as $line) {
            if ($line['sign'] === ' ' || $line['sign'] === '-') {
                $lines[] = $line['text'];
            }
        }

        return $lines;
    }

    /**
     * The lines the hunk leaves behind in their place.
     *
     * @return list<string>
     */
    public function newLines(): array
    {
        $lines = [];

        foreach ($this->lines as $line) {
            if ($line['sign'] === ' ' || $line['sign'] === '+') {
                $lines[] = $line['text'];
            }
        }

        return $lines;
    }

    /**
     * How many lines the hunk adds or removes. Context does not count.
     */
    public function changedLines(): int
    {
        $changed = 0;

        foreach ($this->lines as $line) {
            if ($line['sign'] === '+' || $line['sign'] === '-') {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Determine whether the file ends without a newline once this hunk lands.
     */
    public function newSideEndsWithoutNewline(): bool
    {
        for ($index = count($this->lines) - 1; $index >= 0; $index--) {
            $line = $this->lines[$index];

            if ($line['sign'] === '-') {
                continue;
            }

            return $line['no_newline'];
        }

        return false;
    }
}
