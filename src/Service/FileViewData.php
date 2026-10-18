<?php

namespace App\Service;

use App\Entity\StoredFile;
use App\Exception\FileNotInStorageException;

/** Variables for view_file.html.twig, shared by the /v/ page and custom URLs of non-media files. */
class FileViewData
{
    private const TEXT_LIMIT = 512 * 1024;

    public function __construct(
        private FileService $files,
        private FileAliasService $aliases
    ) {
    }

    /** @return array<string, mixed> */
    public function build(StoredFile $file, bool $canManage): array
    {
        $kind = $file->previewKind();
        $text = null;
        $textTruncated = false;
        $imageSize = null;
        if (!$file->markedForDeletion() && in_array($kind, ['text', 'image'], true)) {
            try {
                $path = $this->files->buildFullFilePath($file);
            } catch (FileNotInStorageException) {
                $path = null;
            }
            if ($path !== null && $kind === 'text' && is_readable($path)) {
                $text = (string) file_get_contents($path, false, null, 0, self::TEXT_LIMIT + 1);
                $textTruncated = strlen($text) > self::TEXT_LIMIT;
                $text = mb_convert_encoding(substr($text, 0, self::TEXT_LIMIT), 'UTF-8', 'UTF-8');
            }
            if ($path !== null && $kind === 'image') {
                $dims = @getimagesize($path);
                if ($dims) {
                    $imageSize = ImageOrientation::swapsDimensions(ImageOrientation::read($path))
                        ? ['width' => $dims[1], 'height' => $dims[0]]
                        : ['width' => $dims[0], 'height' => $dims[1]];
                }
            }
        }
        $alias = $this->aliases->aliasFor($file);
        return [
            'text' => $text,
            'textTruncated' => $textTruncated,
            'imageSize' => $imageSize,
            'file' => $file,
            'kind' => $kind,
            'canManage' => $canManage,
            'size' => UserService::formatSize($file->getInternalSize()),
            'extension' => $file->getOriginalExtension() ?: 'bin',
            // The custom URL when there is one, else what api_ver=v2 hands out.
            'shareUrl' => match (true) {
                $alias !== null => $this->aliases->url($alias),
                (bool) $file->shouldEmbed() => $this->files->generateFullURL($file),
                default => $this->files->generateViewURL($file),
            },
        ];
    }
}
