<?php

namespace App\Controller;

use App\Exception\UploadBlockedException;
use App\Service\PurgeNotCancellable;
use App\Service\PurgeService;
use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Entity\User;
use App\Repository\StoredFileRepository;
use App\Service\CursorService;
use App\Service\DeviceTokenService;
use App\Service\FileIconResolver;
use App\Service\FileService;
use App\Service\ThumbnailService;
use App\Service\UserService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
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
            if (!$thumbnailPath) {
                throw $this->createNotFoundException('Thumbnail not ready');
            }
            return $this->generateThumbnailServeResponse($file, $thumbnailPath);
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
            } catch (UploadBlockedException $e) {
                $this->addFlash('global-danger', $e->getMessage());
                return $this->redirectToRoute('home');
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
                $directUrl = $fileService->generateFullURL($storedFile);
                $viewUrl = $fileService->generateViewURL($storedFile);
                // api_ver=v2: media keeps the direct link, everything else gets the file page.
                $apiVersion = strtolower(trim((string) $request->request->get('api_ver', 'v1')));
                $fileUrl = $apiVersion === 'v2' && !$storedFile->shouldEmbed() ? $viewUrl : $directUrl;
                if ($request->request->get('plaintext')) {
                    return new Response($fileUrl, 201);
                }
                return new JsonResponse([
                    'file' => $fileUrl,
                    'direct' => $directUrl,
                    'manage' => $viewUrl,
                    'delete' => $fileService->generateDeletionURL($storedFile),
                    'api_ver' => $apiVersion === 'v2' ? 'v2' : 'v1'
                ]);
            } catch (UploadBlockedException $e) {
                if ($request->request->get('plaintext')) {
                    return new Response($e->getMessage(), 423);
                }
                return new JsonResponse(['error' => $e->getMessage()], 423);
            } catch (\Exception $e) {
                $logger->error('Error saving file: ' . $e->getMessage() . " with token " . $remoteToken);
                if ($request->request->get('plaintext')) {
                    return new Response('Upload rejected: invalid token or unusable file', 400);
                }
                return new JsonResponse(['error' => 'Upload rejected: invalid token or unusable file'], 400);
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
            } catch (UploadBlockedException $e) {
                $this->addFlash('global-danger', $e->getMessage());
                return $this->redirectToRoute('home');
            } catch (\Exception $e) {
                $logger->error('Error mirroring file: ' . $e->getMessage());
                $this->addFlash('global-danger', sprintf("Unable to mirror remote file: %s", $e->getMessage()));
                return $this->redirectToRoute('home');
            }
        }
        $this->addFlash('global-danger', 'No input file specified');
        return $this->redirectToRoute('home');
    }


    /**
     * Deletion link handed to upload clients. GET shows a confirmation page (clients such as ShareX open
     * the link in a browser); POST performs the deletion and answers JSON when the client asks for it.
     */
    #[Route("/d/{customUrl}.{fileExtension}", name: "delete_file", methods: ['GET', 'POST'])]
    public function deleteByKeyAction(Request $request, string $customUrl, string $fileExtension, FileService $fileService, LoggerInterface $logger)
    {
        $file = $fileService->getFileForView($customUrl);
        $key = (string) ($request->request->get('key') ?? $request->query->get('key', ''));
        $wantsJson = $request->request->get('plaintext') || $request->query->get('format') === 'json'
            || str_contains((string) $request->headers->get('Accept'), 'application/json');
        if (!$file || !$fileService->verifyDeletionKey($file, $key)) {
            if ($wantsJson) {
                return new JsonResponse(['status' => 'error', 'message' => 'Unknown file or invalid key'], 403);
            }
            throw $this->createNotFoundException();
        }
        $viewData = [
            'file' => $file,
            'key' => $key,
            'extension' => $file->getOriginalExtension() ?: 'bin',
            'size' => UserService::formatSize($file->getInternalSize()),
        ];
        if ($request->isMethod('POST')) {
            if (!$file->markedForDeletion()) {
                $fileService->markForDeletion($file);
                $logger->info("File {$file->getId()} marked for deletion through its deletion link");
            }
            if ($wantsJson) {
                return new JsonResponse(['status' => 'ok', 'deleted' => true]);
            }
            return $this->render('delete_file.html.twig', $viewData + ['done' => true]);
        }
        return $this->render('delete_file.html.twig', $viewData + ['done' => false]);
    }

    /**
     * Web Share Target (Android): the OS posts shared files and/or a link here. There is no CSRF
     * token in a share sheet POST, so only authenticated sessions are accepted.
     */
    #[Route('/share', name: 'share_target', methods: ['GET', 'POST'])]
    public function shareTargetAction(Request $request, FileService $fileService, LoggerInterface $logger)
    {
        if (!$this->isGranted('ROLE_USER')) {
            $this->addFlash('global-danger', 'Log in once in the installed app, then share to MIU again.');
            return $this->redirectToRoute('auth_login');
        }
        if (!$request->isMethod('POST')) {
            return $this->redirectToRoute('home');
        }
        /** @var User $user */
        $user = $this->getUser();
        if ($user->isPurging()) {
            $this->addFlash('global-danger', UploadBlockedException::MESSAGE);
            return $this->redirectToRoute('cabinet_home');
        }
        $stored = [];
        $failed = 0;
        $files = $request->files->get('meowfile');
        foreach (is_array($files) ? $files : array_filter([$files]) as $file) {
            try {
                $stored[] = $fileService->storeFormUploadFile($file, $user);
            } catch (\Exception $e) {
                $failed++;
                $logger->error('Share target: cannot store file: ' . $e->getMessage());
            }
        }
        // A shared link arrives in "url", or sometimes only inside "text"; mirror it.
        $link = trim((string) $request->request->get('url', ''));
        if ($link === '' && preg_match('#https?://\S+#', (string) $request->request->get('text', ''), $m)) {
            $link = $m[0];
        }
        if ($link !== '') {
            try {
                $stored[] = $fileService->mirrorRemoteFile($link, $user);
            } catch (\Exception $e) {
                $failed++;
                $logger->error('Share target: cannot mirror ' . $link . ': ' . $e->getMessage());
            }
        }
        if ($failed > 0) {
            $this->addFlash('global-danger', $failed === 1 ? 'One shared item could not be stored.' : "{$failed} shared items could not be stored.");
        }
        if (count($stored) === 1) {
            return $this->redirectToRoute('view_file', [
                'customUrl' => $stored[0]->getCustomUrl(),
                'fileExtension' => $stored[0]->getOriginalExtension(),
            ]);
        }
        if (count($stored) > 1) {
            $this->addFlash('global-success', count($stored) . ' files uploaded.');
            return $this->redirectToRoute('cabinet_mypics');
        }
        if ($failed === 0) {
            // Share sheets differ between Android versions; record what actually arrived when it was unusable.
            $logger->error('Share target received nothing usable', [
                'content_type' => $request->headers->get('Content-Type'),
                'content_length' => $request->headers->get('Content-Length'),
                'fields' => $request->request->all(),
                'files' => array_map(fn ($f) => is_array($f) ? count($f) : ($f ? $f->getClientOriginalName() . ' ' . $f->getSize() . 'B err=' . $f->getError() : null), $request->files->all()),
                'user_agent' => $request->headers->get('User-Agent'),
            ]);
            $this->addFlash('global-danger', 'Nothing arrived with the share. The sharing app may not have passed the file along; try sharing from a file manager or the system gallery.');
        }
        return $this->redirectToRoute('home');
    }

    #[Route(path: '/endpoint/dropzone/', name: 'set_file_ajax')]
    public function ajaxUploadAction(Request $request, FileService $fileService, FileIconResolver $iconResolver, LoggerInterface $logger)
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
                $result['view'] = $fileService->generateViewURL($storedFile);
                $result['icon'] = $iconResolver->resolve($storedFile->getInternalMimetype(), $storedFile->getOriginalExtension(), $storedFile->getOriginalName());
                $result['thumbnailable'] = $storedFile->isThumbnailable();
                $result['copy'] = $storedFile->shouldEmbed() ? $result['download'] : $result['view'];
                $code = 200;
            } catch (UploadBlockedException $e) {
                $result['message'] = $e->getMessage();
                $code = 423;
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

    #[Route(path: '/endpoint/setuserrole/', name: 'admin_set_user_role', methods: ['POST'])]
    public function setUserRole(Request $request, UserService $userService, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $result = ['status' => 'ok'];
        $userId = (int) $request->request->get('id');
        $role = (int) $request->request->get('role');
        /** @var User $actor */
        $actor = $this->getUser();
        if ($userId === (int) $actor->getId()) {
            return new JsonResponse(['status' => 'error', 'message' => 'You cannot change your own role'], 403);
        }
        try {
            $user = $userService->setUserRole($actor, $userId, $role);
            $result['role'] = (int) $user->getRole();
            $logger->info("Role of user {$user->getId()} set to {$result['role']} by admin {$actor->getId()}");
        } catch (\Exception $e) {
            $logger->warning("Cannot set role={$role} for user {$userId}: " . $e->getMessage());
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }
        return new JsonResponse($result, $result['status'] === 'ok' ? 200 : 400);
    }

    #[Route(path: '/endpoint/deleteuser/', name: 'admin_delete_user', methods: ['POST'])]
    public function deleteUser(Request $request, UserService $userService, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $userId = (int) $request->request->get('id');
        $confirmation = trim((string) $request->request->get('confirm', ''));
        $markFiles = filter_var($request->request->get('mark_files'), FILTER_VALIDATE_BOOLEAN);
        /** @var User $actor */
        $actor = $this->getUser();
        if ($userId === (int) $actor->getId()) {
            return new JsonResponse(['status' => 'error', 'message' => 'You cannot delete your own account'], 403);
        }
        // The typed username is checked server-side too, not only in the modal.
        $target = $userService->findUser($userId);
        if (!$target || $confirmation !== $target->getLogin()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Username confirmation does not match'], 400);
        }
        try {
            $report = $userService->deleteUser($actor, $userId, $markFiles);
            $logger->info("User {$userId} ({$report['login']}) deleted by admin {$actor->getId()}; files={$report['files']} marked={$report['marked']}");
            return new JsonResponse(['status' => 'ok'] + $report);
        } catch (\Exception $e) {
            $logger->warning("Cannot delete user {$userId}: " . $e->getMessage());
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /** Admin purge: schedule the deletion of every file of a user; the typed username is the confirmation. */
    #[Route(path: '/endpoint/purgeuserfiles/', name: 'admin_purge_user_files', methods: ['POST'])]
    public function purgeUserFiles(Request $request, UserService $userService, PurgeService $purge, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $userId = (int) $request->request->get('id');
        $confirmation = trim((string) $request->request->get('confirm', ''));
        /** @var User $actor */
        $actor = $this->getUser();
        $target = $userService->findUser($userId);
        if (!$target || $confirmation !== $target->getLogin()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Username confirmation does not match'], 400);
        }
        $status = $purge->purge($target, "admin {$actor->getId()}");
        return new JsonResponse(['status' => 'ok', 'purge' => $status]);
    }

    #[Route(path: '/endpoint/cancelpurge/', name: 'admin_cancel_purge', methods: ['POST'])]
    public function cancelUserPurge(Request $request, UserService $userService, PurgeService $purge, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $userId = (int) $request->request->get('id');
        /** @var User $actor */
        $actor = $this->getUser();
        $target = $userService->findUser($userId);
        if (!$target) {
            return new JsonResponse(['status' => 'error', 'message' => 'User not found'], 404);
        }
        try {
            $status = $purge->cancel($target, "admin {$actor->getId()}");
        } catch (PurgeNotCancellable $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 409);
        }
        return new JsonResponse(['status' => 'ok', 'purge' => $status]);
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

    #[Route(path: '/endpoint/revokedevice/', name: 'user_revoke_device', methods: ['POST'])]
    public function revokeDevice(Request $request, DeviceTokenService $deviceTokens, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        $id = (int) $request->request->get('id');
        try {
            $deviceTokens->revoke($user, $id);
            $logger->info("Device token {$id} revoked by user {$user->getId()} from the web");
        } catch (\Exception $e) {
            return new JsonResponse(['status' => 'error', 'message' => 'Device not found'], 404);
        }
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(path: '/endpoint/regeneratetoken/', name: 'user_regenerate_token', methods: ['POST'])]
    public function regenerateToken(UserService $userService, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        $result = ['status' => 'ok'];
        try {
            $result['token'] = $userService->regenerateToken($user);
            $logger->info("Remote token regenerated by user {$user->getId()}");
        } catch (\Exception $e) {
            $logger->error("Cannot regenerate token for user {$user->getId()}: " . $e->getMessage());
            $result['status'] = 'error';
            $result['message'] = 'Unable to generate a new token';
        }
        return new JsonResponse($result, $result['status'] === 'ok' ? 200 : 500);
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
        return $this->renderHistoryPageResponse($request, $pageData, 'user_next_files_page', $fileService, $twig, $orderBy->group);
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
        return $this->renderHistoryPageResponse($request, $pageData, 'admin_next_anonymous_page', $fileService, $twig, $orderBy->group);
    }

    /**
     * Renders one page of file cards for infinite scrolling. Page items may be
     * upload records (user history) or bare stored files (anonymous history).
     */
    private function renderHistoryPageResponse(Request $request, array $pageData, string $nextPageRoute, FileService $fileService, \Twig\Environment $twig, string $group = 'none'): JsonResponse
    {
        $result = [];
        $rendered = [];
        $removalPivot = $fileService->getDeletionPivotDate();
        foreach ($pageData['files'] as $item) {
            // Each card carries its group label; the page script inserts a header where it changes.
            $rendered[] = $twig->render('partials/control_panel_file.html.twig', [
                'item' => $item instanceof UploadRecord ? $item->getImage() : $item,
                'pivot' => $removalPivot,
                'group' => $group,
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
