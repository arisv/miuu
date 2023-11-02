<?php

namespace App\MessageHandler;

use App\Message\GenerateThumbnailMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateThumbnailMessageHandler
{
    public function __invoke(GenerateThumbnailMessage $message)
    {
        // do something with your message
    }
}
