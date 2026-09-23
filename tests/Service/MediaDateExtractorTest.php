<?php

namespace App\Tests\Service;

use App\Service\MediaDateExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

final class MediaDateExtractorTest extends TestCase
{
    private const MEDIA = __DIR__ . '/../Fixtures/media/';

    private MediaDateExtractor $extractor;
    private \DateTimeZone $berlin;

    protected function setUp(): void
    {
        $this->extractor = new MediaDateExtractor();
        $this->berlin = new \DateTimeZone('Europe/Berlin');
    }

    public function testExifOffsetTagWinsOverTheNaiveZone(): void
    {
        self::assertSame(
            [(new \DateTimeImmutable('2014-07-05 18:30:00+09:00'))->getTimestamp(), 'exif'],
            $this->extractor->extract(self::MEDIA . 'exif-offset.jpg', 'image/jpeg', $this->berlin),
        );
    }

    public function testExifWithoutOffsetIsReadInTheGivenZone(): void
    {
        self::assertSame(
            [(new \DateTimeImmutable('2011-02-03 04:05:06', $this->berlin))->getTimestamp(), 'exif'],
            $this->extractor->extract(self::MEDIA . 'exif-naive.jpg', 'image/jpeg', $this->berlin),
        );
    }

    public function testZeroExifDateFallsBackToMtime(): void
    {
        $copy = tempnam(sys_get_temp_dir(), 'miutest');
        copy(self::MEDIA . 'exif-zero.jpg', $copy);
        touch($copy, 1_300_000_000);
        try {
            self::assertSame([1_300_000_000, 'mtime'], $this->extractor->extract($copy, 'image/jpeg', $this->berlin));
        } finally {
            unlink($copy);
        }
    }

    public function testVideoCreationTime(): void
    {
        if ((new ExecutableFinder())->find('ffprobe') === null) {
            self::markTestSkipped('ffprobe is not installed');
        }
        self::assertSame(
            [(new \DateTimeImmutable('2016-08-09T10:11:12Z'))->getTimestamp(), 'video'],
            $this->extractor->extract(self::MEDIA . 'video-created.mp4', 'video/mp4', $this->berlin),
        );
    }

    public function testOtherTypesUseMtime(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'miutest');
        file_put_contents($file, 'hello');
        touch($file, 1_400_000_000);
        try {
            self::assertSame([1_400_000_000, 'mtime'], $this->extractor->extract($file, 'text/plain', $this->berlin));
        } finally {
            unlink($file);
        }
    }

    public function testPlausibility(): void
    {
        self::assertFalse(MediaDateExtractor::plausible(0));
        self::assertFalse(MediaDateExtractor::plausible(-2_082_844_800), 'QuickTime 1904 epoch');
        self::assertFalse(MediaDateExtractor::plausible(time() + 2 * 86400));
        self::assertTrue(MediaDateExtractor::plausible(1_000_000_000));
    }
}
