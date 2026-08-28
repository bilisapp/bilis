<?php

namespace App\Services\Autofix;

/**
 * Parses a unified diff into files and hunks, in pure PHP.
 *
 * The write path never shells out to `git apply`: Bilis has no clone of the
 * repository and no working tree to apply anything into. The diff is read
 * here, applied against blobs fetched from the GitHub API, and pushed back
 * through the Git Data API — so this parser is the only thing standing between
 * an agent's patch and what the validator gets to reason about.
 *
 * It is deliberately strict. Anything it cannot make sense of becomes a
 * `DiffParseException`, which the validator turns into a rejection rather than
 * a guess.
 */
class UnifiedDiffParser
{
    /**
     * The marker git writes for a file whose last line has no newline.
     */
    public const NO_NEWLINE_MARKER = '\ No newline at end of file';

    /**
     * Parse a unified diff.
     *
     * @return list<DiffFile>
     *
     * @throws DiffParseException
     */
    public function parse(string $diff): array
    {
        $lines = preg_split("/\r?\n/", $diff);

        if ($lines === false) {
            throw new DiffParseException('The diff could not be read.');
        }

        /** @var list<DiffFile> $files */
        $files = [];

        /** @var array<string, mixed>|null $current */
        $current = null;

        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];

            if (str_starts_with($line, 'diff --git ')) {
                $files = $this->flush($files, $current);
                $current = $this->startFile($this->pathsFromGitHeader($line));

                continue;
            }

            if ($current === null) {
                /*
                 * A diff without the `diff --git` header still starts a file
                 * at its `---` line — some agents hand back plain patches.
                 */
                if (str_starts_with($line, '--- ')) {
                    $current = $this->startFile([null, null]);
                } else {
                    continue;
                }
            }

            if (str_starts_with($line, '--- ')) {
                $path = $this->headerPath(mb_substr($line, 4));

                if ($path === null) {
                    $current['is_new'] = true;
                } else {
                    $current['old_path'] = $path;
                }

                continue;
            }

            if (str_starts_with($line, '+++ ')) {
                $path = $this->headerPath(mb_substr($line, 4));

                if ($path === null) {
                    $current['is_deleted'] = true;
                } else {
                    $current['new_path'] = $path;
                }

                continue;
            }

            if (str_starts_with($line, 'new file mode ')) {
                $current['is_new'] = true;
                $current['mode'] = trim(mb_substr($line, 14));

                continue;
            }

            if (str_starts_with($line, 'deleted file mode ')) {
                $current['is_deleted'] = true;

                continue;
            }

            if (str_starts_with($line, 'new mode ')) {
                $current['mode'] = trim(mb_substr($line, 9));

                continue;
            }

            if (str_starts_with($line, 'rename from ')) {
                $current['is_rename'] = true;
                $current['old_path'] = $this->stripPrefix(trim(mb_substr($line, 12)));

                continue;
            }

            if (str_starts_with($line, 'rename to ')) {
                $current['is_rename'] = true;
                $current['new_path'] = $this->stripPrefix(trim(mb_substr($line, 10)));

                continue;
            }

            if ($line === 'GIT binary patch' || (str_starts_with($line, 'Binary files ') && str_ends_with($line, 'differ'))) {
                $current['is_binary'] = true;

                continue;
            }

            if (str_starts_with($line, '@@')) {
                [$hunk, $index] = $this->parseHunk($lines, $index);

                $current['hunks'][] = $hunk;

                continue;
            }
        }

        $files = $this->flush($files, $current);

        if ($files === []) {
            throw new DiffParseException('The diff contains no file changes.');
        }

        return $files;
    }

    /**
     * Read one hunk header and its body.
     *
     * @param  list<string>  $lines
     * @return array{0: DiffHunk, 1: int}
     *
     * @throws DiffParseException
     */
    protected function parseHunk(array $lines, int $index): array
    {
        $header = $lines[$index];

        if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $header, $matches) !== 1) {
            throw new DiffParseException(sprintf('Unreadable hunk header: %s', mb_substr($header, 0, 120)));
        }

        $oldStart = (int) $matches[1];
        $oldCount = $matches[2] === '' ? 1 : (int) $matches[2];
        $newStart = (int) $matches[3];
        $newCount = ($matches[4] ?? '') === '' ? 1 : (int) $matches[4];

        /** @var list<array{sign: string, text: string, no_newline: bool}> $body */
        $body = [];

        $oldSeen = 0;
        $newSeen = 0;
        $count = count($lines);
        $cursor = $index + 1;

        while ($cursor < $count && ($oldSeen < $oldCount || $newSeen < $newCount)) {
            $line = $lines[$cursor];

            if ($line === '' && $cursor === $count - 1) {
                break;
            }

            $sign = $line === '' ? ' ' : mb_substr($line, 0, 1);
            $text = $line === '' ? '' : mb_substr($line, 1);

            if ($sign === '\\') {
                $body = $this->markNoNewline($body);

                $cursor++;

                continue;
            }

            if ($sign !== ' ' && $sign !== '+' && $sign !== '-') {
                break;
            }

            if ($sign !== '+') {
                $oldSeen++;
            }

            if ($sign !== '-') {
                $newSeen++;
            }

            $body[] = ['sign' => $sign, 'text' => $text, 'no_newline' => false];

            $cursor++;
        }

        if ($oldSeen !== $oldCount || $newSeen !== $newCount) {
            throw new DiffParseException(sprintf(
                'Hunk %s promised %d/%d lines and delivered %d/%d.',
                trim($header),
                $oldCount,
                $newCount,
                $oldSeen,
                $newSeen,
            ));
        }

        /*
         * The trailing `\ No newline` marker may sit on the line after the
         * hunk body has been fully consumed.
         */
        if ($cursor < $count && str_starts_with($lines[$cursor], '\\')) {
            $body = $this->markNoNewline($body);
            $cursor++;
        }

        return [new DiffHunk($oldStart, $oldCount, $newStart, $newCount, $body), $cursor - 1];
    }

    /**
     * Flag the last body line as ending without a newline.
     *
     * @param  list<array{sign: string, text: string, no_newline: bool}>  $body
     * @return list<array{sign: string, text: string, no_newline: bool}>
     */
    protected function markNoNewline(array $body): array
    {
        $last = count($body) - 1;

        if ($last < 0) {
            return $body;
        }

        $body[$last] = [
            'sign' => $body[$last]['sign'],
            'text' => $body[$last]['text'],
            'no_newline' => true,
        ];

        return $body;
    }

    /**
     * Close off the file being parsed, if there is one.
     *
     * @param  list<DiffFile>  $files
     * @param  array<string, mixed>|null  $current
     * @return list<DiffFile>
     *
     * @throws DiffParseException
     */
    protected function flush(array $files, ?array $current): array
    {
        if ($current === null) {
            return $files;
        }

        /** @var list<DiffHunk> $hunks */
        $hunks = $current['hunks'];

        $oldPath = is_string($current['old_path']) ? $current['old_path'] : null;
        $newPath = is_string($current['new_path']) ? $current['new_path'] : null;
        $isNew = (bool) $current['is_new'];
        $isDeleted = (bool) $current['is_deleted'];

        if ($isNew) {
            $oldPath = null;
        }

        if ($isDeleted) {
            $newPath = null;
        }

        if ($oldPath === null && $newPath === null) {
            throw new DiffParseException('A file change names no path on either side.');
        }

        $files[] = new DiffFile(
            oldPath: $oldPath,
            newPath: $newPath,
            isNew: $isNew,
            isDeleted: $isDeleted,
            isRename: (bool) $current['is_rename'],
            isBinary: (bool) $current['is_binary'],
            mode: is_string($current['mode']) ? $current['mode'] : null,
            hunks: $hunks,
        );

        return $files;
    }

    /**
     * Start a fresh file, seeded with whatever the `diff --git` line said.
     *
     * @param  array{0: string|null, 1: string|null}  $paths
     * @return array<string, mixed>
     */
    protected function startFile(array $paths): array
    {
        return [
            'old_path' => $paths[0],
            'new_path' => $paths[1],
            'is_new' => false,
            'is_deleted' => false,
            'is_rename' => false,
            'is_binary' => false,
            'mode' => null,
            'hunks' => [],
        ];
    }

    /**
     * Pull both paths out of a `diff --git a/x b/y` line.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function pathsFromGitHeader(string $line): array
    {
        $rest = mb_substr($line, 11);

        if (preg_match('/^"(.+)" "(.+)"$/', $rest, $matches) === 1) {
            return [$this->stripPrefix($matches[1]), $this->stripPrefix($matches[2])];
        }

        if (preg_match('#^(a/.+?) (b/.+)$#', $rest, $matches) === 1) {
            return [$this->stripPrefix($matches[1]), $this->stripPrefix($matches[2])];
        }

        $parts = explode(' ', $rest);

        if (count($parts) === 2) {
            return [$this->stripPrefix($parts[0]), $this->stripPrefix($parts[1])];
        }

        return [null, null];
    }

    /**
     * Read the path off a `---`/`+++` header, or null for /dev/null.
     */
    protected function headerPath(string $value): ?string
    {
        $value = trim(explode("\t", $value)[0]);

        if ($value === '/dev/null' || $value === '') {
            return null;
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = mb_substr($value, 1, -1);
        }

        return $this->stripPrefix($value);
    }

    /**
     * Drop git's `a/` and `b/` diff prefixes.
     */
    protected function stripPrefix(string $path): string
    {
        if (str_starts_with($path, 'a/') || str_starts_with($path, 'b/')) {
            return mb_substr($path, 2);
        }

        return $path;
    }
}
