<?php

namespace App\Service;

use FFMpeg\FFProbe;

/**
 * When a file was taken or created, for backdated imports: EXIF for images, container tags for
 * videos, and the file's mtime when neither gives a plausible date.
 */
class MediaDateExtractor
{
    const SOURCE_EXIF = 'exif';
    const SOURCE_VIDEO = 'video';
    const SOURCE_MTIME = 'mtime';

    // Date tag => the tag holding its UTC offset (EXIF 2.31), best first.
    private const EXIF_TAGS = [
        'DateTimeOriginal' => 'OffsetTimeOriginal',
        'DateTimeDigitized' => 'OffsetTimeDigitized',
        'DateTime' => 'OffsetTime',
    ];
    // The Apple tag carries the local offset; creation_time is UTC.
    private const VIDEO_TAGS = ['com.apple.quicktime.creationdate', 'creation_time'];

    private FFProbe|false|null $ffprobe = null;

    /**
     * @param \DateTimeZone $naiveTz zone for EXIF times without an offset tag (camera local time)
     * @return array{int, string} unix timestamp and one of the SOURCE_* constants
     */
    public function extract(string $path, string $mime, \DateTimeZone $naiveTz): array
    {
        if (str_starts_with($mime, 'image/') && ($ts = $this->fromExif($path, $naiveTz)) !== null) {
            return [$ts, self::SOURCE_EXIF];
        }
        if (str_starts_with($mime, 'video/') && ($ts = $this->fromVideoTags($path)) !== null) {
            return [$ts, self::SOURCE_VIDEO];
        }
        return [filemtime($path), self::SOURCE_MTIME];
    }

    public static function exifAvailable(): bool
    {
        return function_exists('exif_read_data');
    }

    private function fromExif(string $path, \DateTimeZone $naiveTz): ?int
    {
        if (!self::exifAvailable()) {
            return null;
        }
        $exif = @exif_read_data($path);
        if (!is_array($exif)) {
            return null;
        }
        foreach (self::EXIF_TAGS as $tag => $offsetTag) {
            $value = $this->tagString($exif[$tag] ?? null);
            if ($value === null) {
                continue;
            }
            $tz = $naiveTz;
            $offset = $this->tagString($exif[$offsetTag] ?? null);
            if ($offset !== null && preg_match('/^[+-]\d{2}:\d{2}$/', $offset)) {
                $tz = new \DateTimeZone($offset);
            }
            $date = \DateTimeImmutable::createFromFormat('!Y:m:d H:i:s', $value, $tz);
            if ($date && self::plausible($date->getTimestamp())) {
                return $date->getTimestamp();
            }
        }
        return null;
    }

    private function fromVideoTags(string $path): ?int
    {
        $probe = $this->ffprobe();
        if (!$probe) {
            return null;
        }
        try {
            $tags = $probe->format($path)->get('tags') ?? [];
        } catch (\Throwable) {
            return null;
        }
        foreach (self::VIDEO_TAGS as $tag) {
            $value = $this->tagString($tags[$tag] ?? null);
            if ($value === null) {
                continue;
            }
            try {
                $ts = (new \DateTimeImmutable($value))->getTimestamp();
            } catch (\Exception) {
                continue;
            }
            if (self::plausible($ts)) {
                return $ts;
            }
        }
        return null;
    }

    private function ffprobe(): ?FFProbe
    {
        if ($this->ffprobe === null) {
            try {
                $this->ffprobe = FFProbe::create();
            } catch (\Throwable) {
                $this->ffprobe = false;
            }
        }
        return $this->ffprobe ?: null;
    }

    private function tagString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value, " \0");
        return $value === '' ? null : $value;
    }

    /** Zero dates, epoch placeholders (1970, QuickTime's 1904) and future clocks are camera junk. */
    public static function plausible(int $ts): bool
    {
        return $ts > 0 && $ts <= time() + 86400;
    }
}
