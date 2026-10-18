<?php

namespace App\Api;

use App\Entity\FileNameChange;
use App\Entity\StoredFile;
use App\Service\FileAliasService;
use App\Service\FileIconResolver;
use App\Service\FileNameRules;
use App\Service\FileService;
use App\Service\UserService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class FileNormalizer
{
    public function __construct(
        private FileService $files,
        private FileIconResolver $icons,
        private RouterInterface $router,
        private FileAliasService $aliases
    ) {
    }

    /** @return array<string, mixed> */
    public function normalize(StoredFile $file, bool $withDeleteLink = false): array
    {
        return $this->build($file, $withDeleteLink, $this->aliases->aliasFor($file));
    }

    /** @return array<string, mixed> */
    private function build(StoredFile $file, bool $withDeleteLink, ?string $alias): array
    {
        $deleted = $file->markedForDeletion();
        $custom = $alias !== null ? $this->aliases->url($alias) : null;
        $direct = $this->files->generateFullURL($file);
        $view = $this->files->generateViewURL($file);
        $nameParts = FileNameRules::split((string) $file->getOriginalName());
        $data = [
            'id' => $file->getId(),
            'slug' => $file->getCustomUrl(),
            'name' => $file->getOriginalName(),
            // What a rename edits, and the extension it keeps (taken from the name, unlike "extension").
            'base_name' => $nameParts['base'],
            'name_extension' => $nameParts['extension'],
            'extension' => $file->getOriginalExtension(),
            // Path segment of the /u/ link, or null; "slug" is the generated token.
            'custom_url' => $alias,
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
                // The custom URL replaces the default share link when set.
                'share' => $custom ?? ($file->shouldEmbed() ? $direct : $view),
                'custom' => $custom,
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

    /** @return array<string, mixed> */
    public function nameChange(FileNameChange $change): array
    {
        return [
            'id' => $change->getId(),
            'old_name' => $change->getOldName(),
            'new_name' => $change->getNewName(),
            'revert' => $change->isRevert(),
            'renamed_at' => $change->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'renamed_by' => $change->getRenamedBy()?->getLogin(),
        ];
    }

    /**
     * @param iterable<StoredFile> $files
     * @return list<array<string, mixed>>
     */
    public function normalizeMany(iterable $files, bool $withDeleteLink = false): array
    {
        $files = is_array($files) ? $files : iterator_to_array($files, false);
        $aliases = $this->aliases->aliasesFor($files);
        $out = [];
        foreach ($files as $file) {
            $out[] = $this->build($file, $withDeleteLink, $aliases[$file->getId()] ?? null);
        }
        return $out;
    }
}
