<?php

namespace App\Service;

use App\Entity\StoredFile;
use App\Message\GenerateThumbnailMessage;
use Doctrine\ORM\EntityManagerInterface;
use FFMpeg\FFMpeg;
use FFMpeg\Media\Video;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ThumbnailService
{
    const THUMBNAIL_SUB_DIRECTORY = "thumbs";
    const THUMBNAIL_WIDTH = 350;
    const THUMBNAIL_QUALITY = 91;
    public function __construct(
        private string $projectDir,
        private string $storageDir,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        private EntityManagerInterface $em
    ) {
    }

    public function tryGettingThumbnail(StoredFile $file): ?string
    {
        if (!$file->isThumbnailable()) {
            return null;
        }
        $thumbnailPath = $this->checkForExistingThumbnail($file);
        if ($thumbnailPath) {
            return $thumbnailPath;
        }
        $this->scheduleThumbnailGeneration($file);
        return null;
    }

    private function checkForExistingThumbnail(StoredFile $file): ?string
    {
        $path = join(DIRECTORY_SEPARATOR, [
            $this->projectDir,
            $this->storageDir,
            $file->relativeThumbPath()
        ]);
        return file_exists($path) ? $path : null;
    }

    public function generateThumbnail(StoredFile $file): ?string
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

        $fullFilePath = join(DIRECTORY_SEPARATOR, [
            $this->projectDir,
            $this->storageDir,
            $file->relativePath()
        ]);

        $destinationThumbnail = $destinationDir . DIRECTORY_SEPARATOR . $file->getInternalName();

        if ($file->isMimeType(["image"])) {
            $success = $this->generateImageThumbnail(from: $fullFilePath, to: $destinationThumbnail);
            if (!$success) {
                $this->logger->warning("GD failed to create a thumbnail", [
                    $file->getId(),
                    $fullFilePath
                ]);
                return null;
            }
            return $destinationThumbnail;
        }

        if ($file->isMimeType(["video"])) {
            $success = $this->generateVideoThumbnail(from: $fullFilePath, to: $destinationThumbnail);
            if (!$success) {
                $this->logger->warning("FFMPEG failed to create a thumbnail", [
                    $file->getId(),
                    $fullFilePath
                ]);
                return null;
            }
            return $destinationThumbnail;
        }
        $this->logger->warning("Attempting to create a thumbnail for unsupported type", [
            $file->getId(),
            $fullFilePath
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
        /** @var Video $video */
        $video = $ffmpeg->open($from);
        $temp = tempnam("/tmp", "MIUU");
        $video
            ->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds(0))
            ->save($temp);
        $succ = $this->generateImageThumbnail($temp, $to);
        unlink($temp);
        return $succ;
    }

    public function scheduleThumbnailGeneration(StoredFile $file)
    {
        $msg = new GenerateThumbnailMessage($file);
        $this->bus->dispatch($msg);
    }

    public function handleGenerationMessage(GenerateThumbnailMessage $msg)
    {
        $file = $this->em->getRepository(StoredFile::class)->findOneBy([
            'id' => $msg->fileId
        ]);

        if (!$file instanceof StoredFile) {
            throw new \Exception(sprintf("File %d not found", $msg->fileId));
        }

        $thumbnailPath = $this->checkForExistingThumbnail($file);
        if ($thumbnailPath) {
            return;
        }

        $this->generateThumbnail($file);
    }
}
