<?php

namespace App\Service;

use App\Entity\StoredFile;
use FFMpeg\FFMpeg;
use LogicException;
use Psr\Log\LoggerInterface;

class ThumbnailService
{
    const THUMBNAIL_SUB_DIRECTORY = "thumbs";
    const THUMBNAIL_WIDTH = 200;
    const THUMBNAIL_QUALITY = 90;
    public function __construct(
        private string $projectDir,
        private string $storageDir,
        private LoggerInterface $logger
    ) {
    }

    public function tryGettingThumbnail(StoredFile $file, string $path): ?string
    {
        if (!$file->isThumbnailable()) {
            return null;
        }
        $thumbnailPath = $this->checkForExistingThumbnail($file);
        if ($thumbnailPath) {
            return $thumbnailPath;
        }
        $thumbnailPath = $this->generateThumbnail($file, $path);
        return $thumbnailPath;
    }

    private function checkForExistingThumbnail(StoredFile $file): ?string
    {
        $path = join(DIRECTORY_SEPARATOR, [
            $this->projectDir,
            $this->storageDir,
            self::THUMBNAIL_SUB_DIRECTORY,
            $file->storageSubdirectory(),
            $file->getInternalName()
        ]);
        return file_exists($path) ? $path : null;
    }

    public function generateThumbnail(StoredFile $file, string $path): ?string
    {
        $destinationDir = join(DIRECTORY_SEPARATOR, [
            $this->projectDir,
            $this->storageDir,
            self::THUMBNAIL_SUB_DIRECTORY,
            $file->storageSubdirectory()
        ]);

        if (!is_dir($destinationDir)) {
            mkdir(directory: $destinationDir, recursive: true);
        }

        $destinationThumbnail = $destinationDir . DIRECTORY_SEPARATOR . $file->getInternalName();

        if ($file->isMimeType(["image"])) {
            $success = $this->generateImageThumbnail(from: $path, to: $destinationThumbnail);
            if (!$success) {
                $this->logger->warning("GD failed to create a thumbnail", [
                    $file->getId(),
                    $path
                ]);
                return null;
            }
            return $destinationThumbnail;
        }

        if ($file->isMimeType(["video"])) {
            $success = $this->generateVideoThumbnail(from: $path, to: $destinationThumbnail);
            if (!$success) {
                $this->logger->warning("FFMPEG failed to create a thumbnail", [
                    $file->getId(),
                    $path
                ]);
                return null;
            }
            return $destinationThumbnail;
        }
        $this->logger->warning("Attempting to create a thumbnail for unsupported type", [
            $file->getId(),
            $path
        ]);
        return null;
    }

    private function generateImageThumbnail(string $from, string $to): bool
    {
        $exifType = exif_imagetype($from);
        switch ($exifType) {
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($from);
                break;
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($from);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($from);
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
                break;
            case IMAGETYPE_BMP:
                $image = imagecreatefrombmp($from);
                break;
            case IMAGETYPE_WEBP:
                $image = imagecreatefromwebp($from);
                break;
            case IMAGETYPE_XBM: //IMAGETYPE_XBM
                $image = imagecreatefromxbm($from);
                break;
            default:
                return false;
        }
        $resized = imagescale($image, self::THUMBNAIL_WIDTH);
        return imagewebp($resized, $to, self::THUMBNAIL_QUALITY);
    }

    private function generateVideoThumbnail(string $from, string $to): bool
    {
        $ffmpeg = FFMpeg::create();
        return false;
    }
}
