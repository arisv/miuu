<?php

namespace App\Api;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Exception\ApiException;
use App\Exception\FileEditException;
use App\Service\FileRenameService;

/**
 * Rename and custom-URL actions with their JSON payloads, shared by the native API and the web
 * gallery so both answer in the same shape. Domain errors become ApiExceptions.
 */
class FileEditActions
{
    public const HISTORY_LIMIT = 50;
    public const HISTORY_MAX = 100;

    public function __construct(
        private FileRenameService $renames,
        private FileNormalizer $normalizer
    ) {
    }

    /** @return array{file: array<string, mixed>, changed: bool, entry: ?array<string, mixed>} */
    public function rename(StoredFile $file, string $baseName, User $actor): array
    {
        try {
            $change = $this->renames->rename($file, $baseName, $actor);
        } catch (FileEditException $e) {
            throw $this->apiError($e);
        }
        return [
            'file' => $this->normalizer->normalize($file, true),
            'changed' => $change !== null,
            'entry' => $change ? $this->normalizer->nameChange($change) : null,
        ];
    }

    /** @return array{file: array<string, mixed>, changed: bool, entry: ?array<string, mixed>} */
    public function revert(StoredFile $file, int $entryId, User $actor): array
    {
        try {
            $change = $this->renames->revert($file, $entryId, $actor);
        } catch (FileEditException $e) {
            throw $this->apiError($e);
        }
        return [
            'file' => $this->normalizer->normalize($file, true),
            'changed' => $change !== null,
            'entry' => $change ? $this->normalizer->nameChange($change) : null,
        ];
    }

    /** @return array{history: list<array<string, mixed>>} */
    public function history(StoredFile $file, int $limit = self::HISTORY_LIMIT): array
    {
        $limit = max(1, min(self::HISTORY_MAX, $limit));
        return ['history' => array_map($this->normalizer->nameChange(...), $this->renames->history($file, $limit))];
    }

    private function apiError(FileEditException $e): ApiException
    {
        return match ($e->reason) {
            FileEditException::HISTORY_NOT_FOUND => ApiException::notFound($e->getMessage()),
            default => ApiException::validation([$e->field ?? 'file' => [$e->getMessage()]]),
        };
    }
}
