<?php

namespace App\Message;

use App\Entity\StoredFile;

final class GenerateThumbnailMessage
{
    public int $fileId;
    public function __construct(
        StoredFile $file
    )
    {
        $this->fileId = $file->getId();
    }
}
