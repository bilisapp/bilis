<?php

namespace App\Services\Docs;

/**
 * A deliberately small front matter reader for the documentation files.
 *
 * The docs are ours, so the format is a flat `key: value` block between two
 * `---` fences — enough for a title, a description and a nav order, and not
 * enough to justify a YAML dependency.
 */
class FrontMatter
{
    /**
     * Split a document into its front matter attributes and its markdown body.
     *
     * @return array{attributes: array<string, string>, body: string}
     */
    public static function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $contents = str_replace("\r\n", "\n", $contents);

        if (! str_starts_with($contents, "---\n")) {
            return ['attributes' => [], 'body' => $contents];
        }

        $end = strpos($contents, "\n---", 3);

        if ($end === false) {
            return ['attributes' => [], 'body' => $contents];
        }

        $block = substr($contents, 4, $end - 3);
        $body = ltrim(substr($contents, $end + 4), "\n");

        return ['attributes' => self::attributes($block), 'body' => $body];
    }

    /**
     * Read the `key: value` lines of a front matter block.
     *
     * @return array<string, string>
     */
    private static function attributes(string $block): array
    {
        $attributes = [];

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            $attributes[$key] = trim($value, '"\'');
        }

        return $attributes;
    }
}
