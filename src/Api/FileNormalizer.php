<?php

namespace App\Api;

use App\Entity\StoredFile;
use App\Service\FileIconResolver;
use App\Service\FileService;
use App\Service\UserService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class FileNormalizer
{
    public function __construct(
        private FileService $files,
        private FileIconResolver $icons,
        private RouterInterface $router
    ) {
    }

    /** @return array<string, mixed> */
    public function normalize(StoredFile $file, bool $withDeleteLink = false): array
    {
        $deleted = $file->markedForDeletion();
        $direct = $this->files->generateFullURL($file);
        $view = $this->files->generateViewURL($file);
        $data = [
            'id' => $file->getId(),
            'slug' => $file->getCustomUrl(),
            'name' => $file->getOriginalName(),
            'extension' => $file->getOriginalExtension(),
            'mime' => $file->getInternalMimetype(),
            'size' => (int) $file->getInternalSize(),
            'size_formatted' => UserService::formatSize($file->getInternalSize()),
            'uploaded_at' => (new \DateTimeImmutable('@' . $file->getDate()))->format(\DateTimeInterface::ATOM),
            'kind' => $file->previewKind(),
            'thumbnailable' => $file->isThumbnailable(),
            'embeddable' => (bool) $file->shouldEmbed(),
            'deleted' => $deleted,
            'deleted_at' => $file->getMarkedForDeletionAt()?->format(\DateTimeInterface::ATOM),
            'icon' => $this->icons->resolve($file->getInternalMimetype(), $file->getOriginalExtension(), $file->getOriginalName()),
            'urls' => [
                'direct' => $direct,
                'view' => $view,
                'share' => $file->shouldEmbed() ? $direct : $view,
                'thumbnail' => ($file->isThumbnailable() && !$deleted) ? $this->router->generate('serve_file_thumbnail', [
                    'customUrl' => $file->getCustomUrl(),
                    'fileExtension' => $file->getOriginalExtension(),
                ], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            ],
        ];
        if ($withDeleteLink) {
            $data['urls']['delete'] = $this->files->generateDeletionURL($file);
        }
        return $data;
    }

    /**
     * @param iterable<StoredFile> $files
     * @return list<array<string, mixed>>
     */
    public function normalizeMany(iterable $files, bool $withDeleteLink = false): array
    {
        $out = [];
        foreach ($files as $file) {
            $out[] = $this->normalize($file, $withDeleteLink);
        }
        return $out;
    }
}
