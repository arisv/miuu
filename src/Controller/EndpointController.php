<?php

namespace App\Controller;

use App\Entity\StoredFile;
use App\Repository\StoredFileRepository;
use App\Service\FileService;
use Psr\Log\LoggerInterface;
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
    /**
     * @Route("/{customUrl}.{fileExtension}", name="get_file_custom_url")
     */
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

    /**
     * @Route("/getfile/", name="set_file_form")
     */
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
                if($request->request->get('plaintext'))
                {
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

    public function mirrorFileAction(Request $request)
    {

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
        if($request->files->has('meowfile'))
        {
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
}