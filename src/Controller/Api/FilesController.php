<?php

namespace App\Controller\Api;

use App\Exception\UploadBlockedException;
use App\Api\FileNormalizer;
use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Entity\User;
use App\Exception\ApiException;
use App\Service\CursorService;
use App\Service\FileService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/files')]
class FilesController extends AbstractController
{
    public function __construct(
        private FileService $files,
        private FileNormalizer $normalizer,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Same query parameters as the web gallery (sort, order, group, calendar-start/end, page-size);
     * the client must resend them along with the cursor.
     */
    #[Route('', name: 'api_files_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user, UserService $users, CursorService $cursors): JsonResponse
    {
        $limit = $cursors->getPageSizeFromRequest($request);
        $order = $cursors->getOrderFromRequest($request);
        $filter = $cursors->getFilterFromRequest($request);
        // A pending purge freezes the account: UserService answers with an empty page and a zero count.
        $page = $users->getUserUploadHistoryPage(
            $user,
            $cursors->decodeCursor($request->query->get('cursor')),
            $order,
            $filter,
            $limit
        );
        $files = array_map(fn ($item) => $item instanceof UploadRecord ? $item->getImage() : $item, $page['files']);
        return $this->json([
            'files' => $this->normalizer->normalizeMany($files),
            'has_next_page' => $page['hasNextPage'],
            'next_cursor' => $page['hasNextPage'] ? ($page['cursor'] ?? null) : null,
            'page_size' => $limit,
            // Files matching the filter (not just this page), for the "N items" subtitle.
            'total_count' => $users->countUserUploadHistory($user, $filter),
            // Effective ordering, after defaults and legacy parameter mapping.
            'sort' => $order->sort,
            'order' => $order->order,
            'group' => $order->group,
        ]);
    }

    /** Month buckets of the user's uploads, newest first. */
    #[Route('/dates', name: 'api_files_dates', methods: ['GET'])]
    public function dates(#[CurrentUser] User $user, UserService $users): JsonResponse
    {
        $out = [];
        foreach ($users->getUploadDateTree($user) as $year => $months) {
            foreach ($months as $month => $count) {
                $out[] = ['year' => (int) $year, 'month' => (int) $month, 'count' => (int) $count];
            }
        }
        return $this->json(['months' => $out]);
    }

    #[Route('', name: 'api_files_upload', methods: ['POST'])]
    public function upload(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $file = $request->files->get('file') ?? $request->files->get('meowfile');
        if (is_array($file)) {
            $file = $file[0] ?? null;
        }
        if (!$file instanceof UploadedFile) {
            throw ApiException::badRequest('no_file', 'Send the file in a multipart field named "file".');
        }
        if (!$file->isValid()) {
            throw ApiException::badRequest('upload_failed', $file->getErrorMessage());
        }
        try {
            $stored = $this->files->storeFormUploadFile($file, $user);
        } catch (UploadBlockedException $e) {
            throw new ApiException(423, 'purge_pending', $e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error('API upload failed for user ' . $user->getId() . ': ' . $e->getMessage());
            throw new ApiException(500, 'upload_failed', 'The file could not be stored.');
        }
        return $this->json(['file' => $this->normalizer->normalize($stored, true)], 201);
    }

    #[Route('/mirror', name: 'api_files_mirror', methods: ['POST'])]
    public function mirror(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $url = trim((string) $request->getPayload()->get('url', ''));
        if ($url === '') {
            throw ApiException::validation(['url' => ['A URL is required']]);
        }
        try {
            $stored = $this->files->mirrorRemoteFile($url, $user);
        } catch (UploadBlockedException $e) {
            throw new ApiException(423, 'purge_pending', $e->getMessage());
        } catch (\Exception $e) {
            throw new ApiException(422, 'mirror_failed', $e->getMessage());
        }
        return $this->json(['file' => $this->normalizer->normalize($stored, true)], 201);
    }

    #[Route('/{id}', name: 'api_files_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, #[CurrentUser] User $user): JsonResponse
    {
        return $this->json(['file' => $this->normalizer->normalize($this->ownedFile($id, $user), true)]);
    }

    #[Route('/{id}', name: 'api_files_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        return $this->toggle($id, $user, 'del');
    }

    #[Route('/{id}/restore', name: 'api_files_restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(int $id, #[CurrentUser] User $user): JsonResponse
    {
        return $this->toggle($id, $user, 'undo');
    }

    private function toggle(int $id, User $user, string $action): JsonResponse
    {
        $this->ownedFile($id, $user); // 404 for missing, foreign and frozen files
        try {
            $this->files->setDeleteStatus($user, $id, $action);
        } catch (\Exception $e) {
            throw ApiException::notFound('File not found');
        }
        return $this->json(['file' => $this->normalizer->normalize($this->ownedFile($id, $user), true)]);
    }

    /**
     * Missing and not-owned files both answer 404, so ids cannot be probed. Files of a user with
     * a pending purge (the caller included) do not exist for anyone either.
     */
    private function ownedFile(int $id, User $user): StoredFile
    {
        $file = $this->em->getRepository(StoredFile::class)->find($id);
        if (!$file || !$this->files->canManage($user, $file) || $user->isPurging() || $this->files->ownerIsPurging($file)) {
            throw ApiException::notFound('File not found');
        }
        return $file;
    }
}
