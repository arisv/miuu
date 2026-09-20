<?php

namespace App\Service;

/**
 * File-name search terms: the query is split on whitespace and every word must occur somewhere
 * in the original name (case- and accent-insensitive through the column collation). `*` matches
 * any run of characters and `?` exactly one; `%`, `_` and the escape character are matched
 * literally.
 */
final class NameSearch
{
    /** Escape character for the LIKE patterns. Not a backslash: MariaDB treats one as a string escape. */
    public const ESCAPE = '!';

    public const MAX_LENGTH = 100;
    public const MAX_TERMS = 8;

    /** Normalised query: trimmed, inner whitespace collapsed, cut to MAX_LENGTH; null when empty. */
    public static function normalize(?string $q): ?string
    {
        if ($q === null) {
            return null;
        }
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        if ($q === '') {
            return null;
        }
        return mb_substr($q, 0, self::MAX_LENGTH);
    }

    /** @return string[] words of a normalised query, at most MAX_TERMS */
    public static function terms(string $q): array
    {
        $words = array_values(array_filter(explode(' ', $q), static fn (string $w) => $w !== ''));
        return array_slice($words, 0, self::MAX_TERMS);
    }

    /** LIKE pattern for one word: `%word%` with globs translated and LIKE metacharacters escaped. */
    public static function likePattern(string $word): string
    {
        $out = '';
        foreach (mb_str_split($word) as $ch) {
            $out .= match ($ch) {
                '*' => '%',
                '?' => '_',
                '%', '_', self::ESCAPE => self::ESCAPE . $ch,
                default => $ch,
            };
        }
        return '%' . $out . '%';
    }
}
