<?php

namespace App\Controller;

use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Entity\User;
use App\Repository\StoredFileRepository;
use App\Service\CursorService;
use App\Service\FileService;
use App\Service\ThumbnailService;
use App\Service\UserService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Tests\Compiler\J;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;


class EndpointController extends AbstractController
{
    private const ANONYMOUS_UPLOADS_DISABLED_MESSAGE = 'Anonymous uploads are disabled, please log in to upload files';

    #[Route("/i/{customUrl}.{fileExtension}", name: "get_file_custom_legacy", stateless: true)]
    public function serveFileLegacyAction(Request $request, $customUrl, $fileExtension, LoggerInterface $logger, FileService $fileService)
    {
        return $this->forward('App\Controller\EndpointController::serveFileDirectAction', [
            'customUrl' => $customUrl,
            'fileExtension' => $fileExtension,
            'logger' => $logger,
            'fileService' => $fileService
        ]);
    }

    #[Route("/thumb/{customUrl}.{fileExtension}", name: "serve_file_thumbnail", stateless: true)]
    public function serveFileThumbnail(Request $request, string $customUrl, string $fileExtension, FileService $fileService, ThumbnailService $thumbnailService, LoggerInterface $logger)
    {
        try {
            /** @var StoredFile $file */
            [$file, $path] = $fileService->getFileByCustomURL($customUrl);

            $thumbnailPath = $thumbnailService->tryGettingThumbnail($file);
            if ($thumbnailPath) {
                $path = $thumbnailPath;
            }
            return $this->generateThumbnailServeResponse($file, $path);
        } catch (\Exception $e) {
            $logger->error('Error serving file: ' . $e->getMessage());
            throw $this->createNotFoundException();
        }
    }

    #[Route("/{customUrl}.{fileExtension}", name: "get_file_custom_url", stateless: true)]
    public function serveFileDirectAction(Request $request, $customUrl, $fileExtension, LoggerInterface $logger, FileService $fileService)
    {
        try {
            /** @var StoredFile $file */
            [$file, $path] = $fileService->getFileByCustomURL($customUrl);
            return $this->generateFileServeResponse($file, $path);
        } catch (\Exception $e) {
            $logger->error('Error serving file: ' . $e->getMessage());
            throw $this->createNotFoundException();
        }
    }

    #[Route("/getfile/", name: "set_file_form")]
    public function formUploadAction(Request $request, FileService $fileService, LoggerInterface $logger)
    {
        $user = $this->getUser();
        if ($request->files->has('meowfile')) {
            if ($request->request->has('private_key')) {
                $logger->warning('Rejected meowfile upload carrying private_key: remote clients must use meowfile_remote');
                return new Response('Token uploads must use the meowfile_remote field', 400);
            }
            if (!$this->canUploadAnonymously($user)) {
                $this->addFlash('global-danger', self::ANONYMOUS_UPLOADS_DISABLED_MESSAGE);
                return $this->redirectToRoute('home');
            }
            $file = $request->files->get('meowfile');
            try {
                $storedFile = $fileService->storeFormUploadFile($file, $user);
                return $this->render('uploadresult.html.twig', [
                    'file' => $storedFile
                ]);
            } catch (\Exception $e) {
                $logger->error('Error saving file: ' . $e->getMessage());
                $this->addFlash('global-danger', 'Internal error while saving file');
                return $this->redirectToRoute('home');
            }
        } else if ($request->files->has('meowfile_remote')) {
            $file = $request->files->get('meowfile_remote');
            $remoteToken = trim((string) $request->request->get('private_key', ''));
            try {
                $storedFile = $fileService->storeRemoteUploadFile($file, $remoteToken);
                $fullUrl = $fileService->generateFullURL($storedFile);
                if ($request->request->get('plaintext')) {
                    return new Response($fullUrl, 201);
                }
                return new JsonResponse(['file' => $fullUrl]);
            } catch (\Exception $e) {
                $logger->error('Error saving file: ' . $e->getMessage() . " with token " . $remoteToken);
            }
        }
        $this->addFlash('global-danger', 'No input file specified');
        return $this->redirectToRoute('home');
    }

    #[Route(path: '/mirrorfile/', name: 'mirror_file')]
    public function mirrorFileAction(Request $request, FileService $fileService, LoggerInterface $logger)
    {
        $user = $this->getUser();
        if ($request->request->has('mirrorfile') && $path = $request->request->get('mirrorfile')) {
            if (!$this->canUploadAnonymously($user)) {
                $this->addFlash('global-danger', self::ANONYMOUS_UPLOADS_DISABLED_MESSAGE);
                return $this->redirectToRoute('home');
            }
            try {
                $storedFile = $fileService->mirrorRemoteFile($path, $user);
                return $this->render('uploadresult.html.twig', [
                    'file' => $storedFile
                ]);
            } catch (\Exception $e) {
                $logger->error('Error mirroring file: ' . $e->getMessage());
                $this->addFlash('global-danger', sprintf("Unable to mirror remote file: %s", $e->getMessage()));
                return $this->redirectToRoute('home');
            }
        }
        $this->addFlash('global-danger', 'No input file specified');
        return $this->redirectToRoute('home');
    }


    #[Route(path: '/endpoint/dropzone/', name: 'set_file_ajax')]
    public function ajaxUploadAction(Request $request, FileService $fileService, LoggerInterface $logger)
    {
        $result = [
            'success' => false,
            'message' => 'No input supplied'
        ];
        $user = $this->getUser();
        $code = 400;
        if (!$this->canUploadAnonymously($user)) {
            $result['message'] = self::ANONYMOUS_UPLOADS_DISABLED_MESSAGE;
            return new JsonResponse($result, 403);
        }
        if ($request->files->has('meowfile')) {
            $file = $request->files->get('meowfile');
            try {
                $storedFile = $fileService->storeFormUploadFile($file, $user);
                $result['success'] = true;
                $result['download'] = $fileService->generateFullURL($storedFile);
                $code = 200;
            } catch (\Exception $e) {
                $logger->error('Error saving dropzone file: ' . $e->getMessage());
            }
        }
        return new JsonResponse($result, $code);
    }

    #[Route(path: '/endpoint/setdeletestatus/', name: 'set_file_delete_status')]
    public function setDeleteStatus(Request $request, FileService $fileService, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $result = ['status' => 'ok'];
        $user = $this->getUser();
        $action = $request->get('action');
        $fileId = $request->get('id');
        try {
            $fileService->setDeleteStatus($user, $fileId, $action);
        } catch (\Exception $e) {
            $logger->error("Cannot mark file {$fileId} for {$action} by user {$user}: " . $e->getMessage());
            $result['status'] = 'error';
        }
        return new JsonResponse($result);
    }


    #[Route(path: '/endpoint/endlesstrash/', name: 'delete_marked')]
    public function deleteMarkedFiles(Request $request, FileService $fileService, LoggerInterface $logger)
    {
        $token = $request->query->get('token');
        try {
            $report = $fileService->deleteMarkedFiles($token);
            return new JsonResponse($report);
        } catch (\Exception $e) {
            $logger->warning("Cannot delete marked files: " . $e->getFile());
            return new JsonResponse(['error' => $e->getMessage()]);
        }
    }

    #[Route(path: '/endpoint/setuseractive/', name: 'admin_set_user_active', methods: ['POST'])]
    public function setUserActive(Request $request, UserService $userService, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $result = ['status' => 'ok'];
        $userId = (int) $request->request->get('id');
        $active = filter_var($request->request->get('active'), FILTER_VALIDATE_BOOLEAN);
        /** @var User $actor */
        $actor = $this->getUser();
        if ($userId === (int) $actor->getId()) {
            $result['status'] = 'error';
            $result['message'] = 'You cannot change your own active status';
            return new JsonResponse($result, 403);
        }
        try {
            $user = $userService->setUserActive($actor, $userId, $active);
            $result['active'] = (bool) $user->getActive();
        } catch (\Exception $e) {
            $logger->warning("Cannot set active={$active} for user {$userId}: " . $e->getMessage());
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }
        return new JsonResponse($result);
    }

    #[Route(path: '/endpoint/resetuserpassword/', name: 'admin_reset_user_password', methods: ['POST'])]
    public function resetUserPassword(Request $request, UserService $userService, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $result = ['status' => 'ok'];
        $userId = (int) $request->request->get('id');
        /** @var User $actor */
        $actor = $this->getUser();
        if ($userId === (int) $actor->getId()) {
            $result['status'] = 'error';
            $result['message'] = 'Use your profile page to change your own password';
            return new JsonResponse($result, 403);
        }
        try {
            [$user, $plainPassword] = $userService->resetPassword($actor, $userId);
            $result['password'] = $plainPassword;
            $logger->info("Password for user {$user->getId()} reset by admin {$actor->getId()}");
        } catch (\Exception $e) {
            $logger->warning("Cannot reset password for user {$userId}: " . $e->getMessage());
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }
        return new JsonResponse($result);
    }

    #[Route(path: '/endpoint/getstoragestats/', name: 'admin_storage_stats')]
    public function getStorageStats(Request $request, UserService $userService, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $result = ['status' => 'ok'];
        try {
            $result['message'] = $userService->getStorageStats();
        } catch (\Exception $e) {
            $logger->warning("Error fetching storage stats: " . $e->getMessage());
            $result['status'] = 'error';
        }
        return new JsonResponse($result);
    }

    #[Route(path: '/endpoint/user_next_files_page/', name: 'user_next_files_page')]
    public function fetchNextFilesPage(Request $request, FileService $fileService, UserService $userService, CursorService $cursorService, \Twig\Environment $twig)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        $orderBy = $cursorService->getOrderFromRequest($request);
        $filter = $cursorService->getFilterFromRequest($request);
        $cursor = $cursorService->decodeCursor($request->query->get('cursor'));
        $pageSize = $cursorService->getPageSizeFromRequest($request);
        $pageData = $userService->getUserUploadHistoryPage($user, $cursor, $orderBy, $filter, $pageSize);
        return $this->renderHistoryPageResponse($request, $pageData, 'user_next_files_page', $fileService, $twig);
    }

    #[Route(path: '/endpoint/admin_next_anonymous_page/', name: 'admin_next_anonymous_page')]
    public function fetchNextAnonymousFilesPage(Request $request, FileService $fileService, UserService $userService, CursorService $cursorService, \Twig\Environment $twig)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $orderBy = $cursorService->getOrderFromRequest($request);
        $filter = $cursorService->getFilterFromRequest($request);
        $cursor = $cursorService->decodeCursor($request->query->get('cursor'));
        $pageSize = $cursorService->getPageSizeFromRequest($request);
        $pageData = $userService->getAnonymousUploadHistoryPage($cursor, $orderBy, $filter, $pageSize);
        return $this->renderHistoryPageResponse($request, $pageData, 'admin_next_anonymous_page', $fileService, $twig);
    }

    /**
     * Renders one page of file cards for infinite scrolling. Page items may be
     * upload records (user history) or bare stored files (anonymous history).
     */
    private function renderHistoryPageResponse(Request $request, array $pageData, string $nextPageRoute, FileService $fileService, \Twig\Environment $twig): JsonResponse
    {
        $result = [];
        $rendered = [];
        $removalPivot = $fileService->getDeletionPivotDate();
        foreach ($pageData['files'] as $item) {
            $rendered[] = $twig->render('partials/control_panel_file.html.twig', [
                'item' => $item instanceof UploadRecord ? $item->getImage() : $item,
                'pivot' => $removalPivot
            ]);
        }
        $result['rendered'] = $rendered;
        $result['hasNextPage'] = $pageData['hasNextPage'];
        $defaultParameters = [];
        foreach ($request->query->all() as $key => $value) {
            if ($value) {
                $defaultParameters[$key] = $value;
            }
        }
        if (!empty($pageData['files'])) {
            $defaultParameters['cursor'] = $pageData['cursor'];
            $result['nextPageRequest'] = $this->generateUrl($nextPageRoute, $defaultParameters);
        }
        return new JsonResponse($result);
    }

    private function canUploadAnonymously($user): bool
    {
        return $user !== null || $this->getParameter('app.allow_anonymous_uploads');
    }

    private function generateFileServeResponse(StoredFile $file, string $path): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setAutoEtag();
        $response->setSharedMaxAge(86400);
        $response->headers->addCacheControlDirective('must-revalidate', true);
        $response->headers->set('Content-Type', $file->getInternalMimetype());
        $response->trustXSendfileTypeHeader();
        $response->headers->set('X-Accel-Redirect', sprintf("/protected-files/%s", $file->relativePath()));
        if ($file->shouldEmbed()) {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $file->getOriginalName());
        } else {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getOriginalName());
        }
        return $response;
    }

    private function generateThumbnailServeResponse(StoredFile $file, string $path): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setAutoEtag();
        $response->setSharedMaxAge(86400);
        $response->headers->addCacheControlDirective('must-revalidate', true);
        $response->headers->set('Content-Type', "image/webp");
        $response->trustXSendfileTypeHeader();
        $response->headers->set('X-Accel-Redirect', sprintf("/protected-files/%s", $file->relativeThumbPath()));
        if ($file->shouldEmbed()) {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $file->getOriginalName());
        } else {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getOriginalName());
        }
        return $response;
    }
}
