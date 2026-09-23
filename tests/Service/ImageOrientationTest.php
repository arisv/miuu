<?php

namespace App\Tests\Service;

use App\Service\ImageOrientation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageOrientationTest extends TestCase
{
    private const MEDIA = __DIR__ . '/../Fixtures/media/';
    private const RED = 0xFF0000;
    private const BLUE = 0x0000FF;

    /** Where the stored top-left (red) and top-right (blue) pixels must end up, per EXIF 2.32. */
    public static function orientations(): array
    {
        return [
            'normal' => [1, 'tl', 'tr', false],
            'mirror horizontal' => [2, 'tr', 'tl', false],
            'rotate 180' => [3, 'br', 'bl', false],
            'mirror vertical' => [4, 'bl', 'br', false],
            'transpose' => [5, 'tl', 'bl', true],
            'rotate 90 cw' => [6, 'tr', 'br', true],
            'transverse' => [7, 'br', 'tr', true],
            'rotate 90 ccw' => [8, 'bl', 'tl', true],
        ];
    }

    #[DataProvider('orientations')]
    public function testApply(int $orientation, string $red, string $blue, bool $swapped): void
    {
        $image = imagecreatetruecolor(3, 2);
        imagefill($image, 0, 0, 0xFFFFFF);
        imagesetpixel($image, 0, 0, self::RED);
        imagesetpixel($image, 2, 0, self::BLUE);

        $out = ImageOrientation::apply($image, $orientation);

        self::assertSame($swapped ? [2, 3] : [3, 2], [imagesx($out), imagesy($out)]);
        self::assertSame($swapped, ImageOrientation::swapsDimensions($orientation));
        self::assertSame(self::RED, $this->corner($out, $red));
        self::assertSame(self::BLUE, $this->corner($out, $blue));
    }

    public function testReadsTheTagFromJpegsOnly(): void
    {
        self::assertSame(6, ImageOrientation::read(self::MEDIA . 'orient-6.jpg'));
        self::assertSame(ImageOrientation::NORMAL, ImageOrientation::read(self::MEDIA . 'exif-naive.jpg'), 'no tag');
        self::assertSame(ImageOrientation::NORMAL, ImageOrientation::read(self::MEDIA . 'video-created.mp4'));
    }

    private function corner(\GdImage $image, string $corner): int
    {
        $x = $corner[1] === 'l' ? 0 : imagesx($image) - 1;
        $y = $corner[0] === 't' ? 0 : imagesy($image) - 1;
        return imagecolorat($image, $x, $y) & 0xFFFFFF;
    }
}
