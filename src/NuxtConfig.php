<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt;

/**
 * What a frontend's `nuxt.config` says, as far as its text says it: the top-level `ssr`
 * and `app.baseURL` — not an `ssr` of a route rule or a `baseURL` of `runtimeConfig`. Only
 * literal values are read; a value computed in code is "expr" and never argued with.
 * Strings and comments are told apart, so `'/api/**'` is a string, not a comment.
 *
 * @internal Read for {@see Nuxt::hints()}.
 */
final readonly class NuxtConfig
{
    /** The names Nuxt looks for, in its order. */
    public const array FILES = ['nuxt.config.ts', 'nuxt.config.js', 'nuxt.config.mjs', 'nuxt.config.mts'];

    /**
     * @param string $file Its name: `nuxt.config.ts`.
     * @param string|null $ssr `true`, `false`, `expr`; null — not there.
     * @param string|null $baseURL The literal, `expr`; null — not there.
     */
    private function __construct(
        public string $file,
        public ?string $ssr,
        public ?string $baseURL,
    ) {
    }

    /** The config file's name in the folder, null when there is none. */
    public static function find(string $folder): ?string
    {
        foreach (self::FILES as $file) {
            if (is_file("{$folder}/{$file}")) {
                return $file;
            }
        }
        return null;
    }

    /** The config of the folder; null when there is none or it cannot be read. */
    public static function read(string $folder): ?self
    {
        $file = self::find($folder);
        $text = $file === null ? false : @file_get_contents("{$folder}/{$file}");
        if (!is_string($text)) {
            return null;
        }
        $text = self::withoutComments($text);
        $root = self::rootObject($text);
        if ($root === null) {
            // Not an object literal there: nothing it says can be read.
            return new self($file, 'expr', 'expr');
        }
        $top = self::entries($text, $root);

        $ssr = null;
        if (isset($top['ssr'])) {
            $ssr = in_array($top['ssr'], ['true', 'false'], true) ? $top['ssr'] : 'expr';
        }
        $baseURL = null;
        if (isset($top['app'])) {
            if (!str_starts_with($top['app'], '{')) {
                $baseURL = 'expr';
            } else {
                $value = self::entries($top['app'], 0)['baseURL'] ?? null;
                if ($value !== null) {
                    $baseURL = preg_match('/^([\'"`])([^\'"`$]*)\1$/', $value, $m) === 1 ? $m[2] : 'expr';
                }
            }
        }
        return new self($file, $ssr, $baseURL);
    }

    /** The text with its comments left out — what is inside strings kept as it is. */
    private static function withoutComments(string $text): string
    {
        $out = '';
        $n = strlen($text);
        for ($i = 0; $i < $n; $i++) {
            $c = $text[$i];
            if ($c === '"' || $c === "'" || $c === '`') {
                $end = self::stringEnd($text, $i);
                $out .= substr($text, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
            if ($c === '/' && ($text[$i + 1] ?? '') === '/') {
                $end = strpos($text, "\n", $i);
                $i = ($end === false ? $n : $end) - 1;
                continue;
            }
            if ($c === '/' && ($text[$i + 1] ?? '') === '*') {
                $end = strpos($text, '*/', $i + 2);
                $i = $end === false ? $n : $end + 1;
                $out .= ' ';
                continue;
            }
            $out .= $c;
        }
        return $out;
    }

    /** Where the string that opens at $at closes (its quote), or the end of the text. */
    private static function stringEnd(string $text, int $at): int
    {
        $quote = $text[$at];
        $n = strlen($text);
        for ($i = $at + 1; $i < $n; $i++) {
            if ($text[$i] === '\\') {
                $i++;
            } elseif ($text[$i] === $quote) {
                return $i;
            }
        }
        return $n - 1;
    }

    /** Where the config's object opens: `defineNuxtConfig({`, else `export default {`. */
    private static function rootObject(string $text): ?int
    {
        foreach (['/defineNuxtConfig\s*\(\s*\{/', '/export\s+default\s*\{/'] as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                return $m[0][1] + strlen($m[0][0]) - 1;
            }
        }
        return null;
    }

    /**
     * The keys of the object that opens at $open, with their values as written (trimmed) —
     * this level only.
     *
     * @return array<string, string>
     */
    private static function entries(string $text, int $open): array
    {
        $entries = [];
        $depth = 0;
        $key = null;
        $start = $open + 1;
        $n = strlen($text);
        for ($i = $open + 1; $i < $n; $i++) {
            $c = $text[$i];
            if ($c === '"' || $c === "'" || $c === '`') {
                $i = self::stringEnd($text, $i);
                continue;
            }
            if ($c === '{' || $c === '[' || $c === '(') {
                $depth++;
                continue;
            }
            if (($c === '}' || $c === ']' || $c === ')') && $depth > 0) {
                $depth--;
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if ($c === ':' && $key === null) {
                $key = trim(substr($text, $start, $i - $start), " \t\r\n'\"`");
                $start = $i + 1;
                continue;
            }
            if ($c === ',' || $c === '}') {
                if ($key !== null) {
                    $entries[$key] = trim(substr($text, $start, $i - $start));
                }
                if ($c === '}') {
                    break;
                }
                $key = null;
                $start = $i + 1;
            }
        }
        return $entries;
    }
}
