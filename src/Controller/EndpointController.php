<?php

namespace App\Controller;

use App\Entity\StoredFile;
use App\Repository\StoredFileRepository;
use App\Service\FileService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class EndpointController extends AbstractController
{
    /**
     * @Route("/i/{customUrl}.{fileExtension}")
     */
    public function serveFileDirectAction(Request $request, $customUrl, $fileExtension, LoggerInterface $logger, FileService $fileService)
    {
        $user = $this->getUser();
        try {
            /** @var StoredFile $file */
            [$file, $path] = $fileService->getFileByCustomURL($customUrl);
            $response = new BinaryFileResponse($path);
            $response->setAutoEtag();
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
}