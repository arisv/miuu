<?php

namespace App\Service;

/**
 * What a file may be renamed to. Renames keep the extension and change only the part before it;
 * the result may hold anything a Unix file name can, except the path separator. Portability
 * warnings (names Windows reserves) are a client concern and are not enforced here.
 */
final class FileNameRules
{
    /** NAME_MAX on common Unix file systems, counted in bytes. */
    public const MAX_BYTES = 255;

    /**
     * Base name and extension. The extension is the text after the last dot, unless that dot
     * starts the name (".bashrc"), ends it ("notes.") or is followed by whitespace ("v1.2 final").
     *
     * @return array{base: string, extension: ?string}
     */
    public static function split(string $name): array
    {
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($name) - 1) {
            return ['base' => $name, 'extension' => null];
        }
        $extension = substr($name, $dot + 1);
        if (preg_match('/\s/u', $extension)) {
            return ['base' => $name, 'extension' => null];
        }
        return ['base' => substr($name, 0, $dot), 'extension' => $extension];
    }

    public static function join(string $base, ?string $extension): string
    {
        return $extension === null ? $base : $base . '.' . $extension;
    }

    /** First reason the name cannot be used, or null when it is acceptable. */
    public static function problem(string $base, ?string $extension): ?string
    {
        if ($base === '') {
            return 'The name cannot be empty.';
        }
        if (!preg_match('//u', $base)) {
            return 'The name is not valid UTF-8.';
        }
        if (str_contains($base, '/')) {
            return 'The name cannot contain "/".';
        }
        if (str_contains($base, "\0")) {
            return 'The name cannot contain NUL characters.';
        }
        $full = self::join($base, $extension);
        if ($full === '.' || $full === '..') {
            return 'The name cannot be "." or "..".';
        }
        if (strlen($full) > self::MAX_BYTES) {
            return sprintf('The name is %d bytes long; the limit is %d.', strlen($full), self::MAX_BYTES);
        }
        return null;
    }

    /** Content-Disposition cannot carry "/" or "\" (HeaderUtils::makeDisposition throws). */
    public static function forDisposition(string $name): string
    {
        return strtr($name, ['/' => '_', '\\' => '_']);
    }
}
