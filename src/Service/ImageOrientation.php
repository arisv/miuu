<?php

namespace App\Service;

/** EXIF Orientation for JPEGs: GD decodes the stored pixels and ignores the tag. */
final class ImageOrientation
{
    const NORMAL = 1;

    /** 1-8 from the file's EXIF, or NORMAL when there is none (or it is not a JPEG). */
    public static function read(string $path): int
    {
        if (!function_exists('exif_read_data') || @exif_imagetype($path) !== IMAGETYPE_JPEG) {
            return self::NORMAL;
        }
        $exif = @exif_read_data($path);
        $value = is_array($exif) ? (int) ($exif['Orientation'] ?? self::NORMAL) : self::NORMAL;
        return $value >= 1 && $value <= 8 ? $value : self::NORMAL;
    }

    /** 5-8 are stored sideways: displayed width and height are swapped. */
    public static function swapsDimensions(int $orientation): bool
    {
        return $orientation >= 5;
    }

    /** The image as it should be displayed. imagerotate() turns counter-clockwise. */
    public static function apply(\GdImage $image, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return $image;
            case 3:
                return imagerotate($image, 180, 0);
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                return $image;
            case 5: // transpose
                $image = imagerotate($image, -90, 0);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return $image;
            case 6:
                return imagerotate($image, -90, 0);
            case 7: // transverse
                $image = imagerotate($image, 90, 0);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return $image;
            case 8:
                return imagerotate($image, 90, 0);
            default:
                return $image;
        }
    }
}
