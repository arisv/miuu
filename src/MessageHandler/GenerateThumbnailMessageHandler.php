<?php

namespace App\MessageHandler;

use App\Message\GenerateThumbnailMessage;
use App\Service\ThumbnailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final class GenerateThumbnailMessageHandler
{
    public function __construct(private ThumbnailService $thumbnailService)
    {
        
    }
    public function __invoke(GenerateThumbnailMessage $message)
    {
        try {
            $this->thumbnailService->handleGenerationMessage($message);
        } catch (Throwable $e) {
            echo $e->getMessage();
            echo $e->getTraceAsString();
        }
    }
}
