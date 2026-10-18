<?php

namespace App\Service;

use App\Entity\StoredFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/** A stored file as the response body: nginx sends the bytes (X-Accel-Redirect), PHP sets the headers. */
class RawFileResponder
{
    /** Shared caches keep raw links for a day; pass $maxAge for links whose target can change. */
    public function respond(StoredFile $file, string $path, ?int $maxAge = null): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setAutoEtag();
        if ($maxAge === null) {
            $response->setSharedMaxAge(86400);
        } else {
            $response->setMaxAge($maxAge);
            $response->setSharedMaxAge($maxAge);
        }
        $response->headers->addCacheControlDirective('must-revalidate', true);
        $response->headers->set('Content-Type', $file->getInternalMimetype());
        $response->trustXSendfileTypeHeader();
        $response->headers->set('X-Accel-Redirect', sprintf("/protected-files/%s", $file->relativePath()));
        if ($file->shouldEmbed()) {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, FileNameRules::forDisposition($file->getOriginalName()));
        } else {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, FileNameRules::forDisposition($file->getOriginalName()));
        }
        return $response;
    }

    /**
     * For URLs in the session firewall (/v/, /u/). The firewall may sign a viewer with cookies in
     * (session or remember-me) and set new cookies on the response, so no shared cache may keep it.
     */
    public function respondTo(Request $request, StoredFile $file, string $path, ?int $maxAge = null): BinaryFileResponse
    {
        $response = $this->respond($file, $path, $maxAge);
        if ($request->cookies->count() > 0) {
            $response->setPrivate();
            $response->headers->removeCacheControlDirective('s-maxage');
        }
        return $response;
    }
}
