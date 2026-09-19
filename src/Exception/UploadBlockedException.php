<?php

namespace App\Exception;

/** The uploader has a purge pending: nothing may be added until it is cancelled or finished. */
class UploadBlockedException extends \RuntimeException
{
    public const MESSAGE = 'Uploads are paused while your files are being deleted. Cancel the deletion on your profile page to upload again.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
