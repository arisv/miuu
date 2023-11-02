<?php

namespace App\Controller;

use App\Entity\StoredFile;
use App\Repository\StoredFileRepository;
use App\Service\CursorService;
use App\Service\FileService;
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

    #[Route("/i/{customUrl}.{fileExtension}", name:"get_file_custom_legacy")]
    public function serveFileLegacyAction(Request $request, $customUrl, $fileExtension, LoggerInterface $logger, FileService $fileService)
    {
        return $this->forward('App\Controller\EndpointController::serveFileDirectAction', [
            'customUrl' => $customUrl,
            'fileExtension' => $fileExtension,
            'logger' => $logger,
            'fileService' => $fileService
        ]);
    }

    #[Route("/{customUrl}.{fileExtension}", name:"get_file_custom_url")]
    public function serveFileDirectAction(Request $request, $customUrl, $fileExtension, LoggerInterface $logger, FileService $fileService)
    {
        try {
            /** @var StoredFile $file */
            [$file, $path] = $fileService->getFileByCustomURL($customUrl);
            $response = new BinaryFileResponse($path);
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
            $response->setAutoEtag();
            $response->setSharedMaxAge(86400);
            $response->headers->addCacheControlDirective('must-revalidate', true);
            $response->headers->set('Content-Type', $file->getInternalMimetype());
            if ($file->shouldEmbed()) {
                $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $file->getOriginalName());
            } else {
                $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getOriginalName());
            }
            return $response;
        } catch (\Exception $e) {
            $logger->error('Error serving file: ' . $e->getMessage());
            throw $this->createNotFoundException();
        }
    }

    #[Route("/getfile/", name:"set_file_form")]
    public function formUploadAction(Request $request, FileService $fileService, LoggerInterface $logger)
    {
        $user = $this->getUser();
        if ($request->files->has('meowfile')) {
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
            $remoteToken = $request->request->get('private_key');
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

    /**
     * @Route("/mirrorfile/", name="mirror_file")
     */
    public function mirrorFileAction(Request $request, FileService $fileService, LoggerInterface $logger)
    {
        $user = $this->getUser();
        if ($request->request->has('mirrorfile') && $path = $request->request->get('mirrorfile')) {
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


    /**
     * @Route("/endpoint/dropzone/", name="set_file_ajax")
     */
    public function ajaxUploadAction(Request $request, FileService $fileService, LoggerInterface $logger)
    {
        $result = [
            'success' => false,
            'message' => 'No input supplied'
        ];
        $user = $this->getUser();
        $code = 400;
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

    /**
     * @Route("/endpoint/setdeletestatus/", name="set_file_delete_status")
     */
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


    /**
     * @Route("/endpoint/endlesstrash/", name="delete_marked")
     */
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

    /**
     * @Route("/endpoint/getstoragestats/", name="admin_storage_stats")
     */
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

    /**
     * @Route("/endpoint/user_next_files_page/", name="user_next_files_page")
     */
    public function fetchNextFilesPage(Request $request, FileService $fileService, UserService $userService, CursorService $cursorService, \Twig\Environment $twig)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        $orderBy = $cursorService->getOrderFromRequest($request);
        $filter = $cursorService->getFilterFromRequest($request);
        $cursor = $cursorService->decodeCursor($request->query->get('cursor'));
        $pageData = $userService->getUserUploadHistoryPage($user, $cursor, $orderBy, $filter);
        $rendered = [];
        $removalPivot = $fileService->getDeletionPivotDate();
        foreach ($pageData['files'] as $file) {
            $rendered[] = $twig->render('partials/control_panel_file.html.twig', [
                'item' => $file->getImage(),
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
            $result['nextPageRequest'] = $this->generateUrl('user_next_files_page', $defaultParameters);
        }
        return new JsonResponse($result);
    }
}