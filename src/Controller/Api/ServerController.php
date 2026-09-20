<?php

namespace App\Controller\Api;

use App\Service\CursorService;
use App\Service\ThumbnailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class ServerController extends AbstractController
{
    /** Public capabilities so a client can validate a server URL before logging in. */
    #[Route('/server', name: 'api_server_info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        return $this->json([
            'name' => 'MIU',
            'api_version' => 1,
            'allow_registration' => (bool) $this->getParameter('app.allow_registration'),
            'allow_anonymous_uploads' => (bool) $this->getParameter('app.allow_anonymous_uploads'),
            'max_upload_bytes' => UploadedFile::getMaxFilesize(),
            'max_remote_file_bytes' => (int) ($_ENV['MAX_REMOTE_FILE_SIZE'] ?? 0),
            'page_sizes' => CursorService::PAGE_SIZES,
            'thumbnail_width' => ThumbnailService::THUMBNAIL_WIDTH,
            'delete_grace_minutes' => (int) ($_ENV['DELETE_MARKED_FILES_AFTER_MINUTES'] ?? 0),
            // GET /files accepts q= (file-name search: words, * and ? globs).
            'search' => true,
        ]);
    }
}
