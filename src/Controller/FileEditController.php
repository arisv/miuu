<?php

namespace App\Controller;

use App\Api\FileEditActions;
use App\Entity\StoredFile;
use App\Entity\User;
use App\Exception\ApiException;
use App\Service\FileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rename and custom-URL actions for the web gallery, answering exactly like the native API
 * (errors as {"error": {...}} through ApiExceptionSubscriber). Changes must carry the page's
 * CSRF token in an X-CSRF-Token header.
 */
#[Route('/endpoint/files/{id}', requirements: ['id' => '\d+'])]
class FileEditController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'file_edit';

    public function __construct(
        private FileService $files,
        private FileEditActions $edits
    ) {
    }

    #[Route('/rename', name: 'file_edit_rename', methods: ['POST'])]
    public function rename(int $id, Request $request): JsonResponse
    {
        $file = $this->editableFile($id, $request);
        return $this->json($this->edits->rename($file, (string) $request->getPayload()->get('base_name', ''), $this->currentUser()));
    }

    #[Route('/name-history', name: 'file_edit_name_history', methods: ['GET'])]
    public function nameHistory(int $id, Request $request): JsonResponse
    {
        $limit = $request->query->getInt('limit', FileEditActions::HISTORY_LIMIT);
        return $this->json($this->edits->history($this->editableFile($id), $limit));
    }

    #[Route('/name-history/{entryId}/revert', name: 'file_edit_name_revert', requirements: ['entryId' => '\d+'], methods: ['POST'])]
    public function revertName(int $id, int $entryId, Request $request): JsonResponse
    {
        return $this->json($this->edits->revert($this->editableFile($id, $request), $entryId, $this->currentUser()));
    }

    #[Route('/custom-url', name: 'file_edit_custom_url_set', methods: ['POST'])]
    public function setCustomUrl(int $id, Request $request): JsonResponse
    {
        $file = $this->editableFile($id, $request);
        return $this->json($this->edits->setCustomUrl($file, (string) $request->getPayload()->get('custom_url', '')));
    }

    #[Route('/custom-url', name: 'file_edit_custom_url_remove', methods: ['DELETE'])]
    public function removeCustomUrl(int $id, Request $request): JsonResponse
    {
        return $this->json($this->edits->removeCustomUrl($this->editableFile($id, $request)));
    }

    /** Pass the request for changes, so its CSRF token is checked. */
    private function editableFile(int $id, ?Request $change = null): StoredFile
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if ($change && !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $change->headers->get('X-CSRF-Token'))) {
            throw new ApiException(403, 'csrf_invalid', 'This page has expired. Reload it and try again.');
        }
        return $this->files->findManageableFile($this->currentUser(), $id) ?? throw ApiException::notFound('File not found');
    }

    private function currentUser(): User
    {
        /** @var User */
        return $this->getUser();
    }
}
