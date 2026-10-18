<?php

namespace App\Service;

use App\Entity\FileNameChange;
use App\Entity\StoredFile;
use App\Entity\User;
use App\Exception\FileEditException;
use App\Repository\FileNameChangeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Renames change only the stored display name (downloads, titles, search and sort follow it);
 * the file on disk keeps its internal name. Every change is recorded so it can be reverted.
 */
class FileRenameService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Gives the file a new base name, keeping its current extension.
     * Returns null when the name does not change; nothing is recorded then.
     *
     * @throws FileEditException invalid_name
     */
    public function rename(StoredFile $file, string $baseName, ?User $actor): ?FileNameChange
    {
        $extension = FileNameRules::split((string) $file->getOriginalName())['extension'];
        $problem = FileNameRules::problem($baseName, $extension);
        if ($problem !== null) {
            throw FileEditException::invalidName($problem);
        }
        return $this->apply($file, FileNameRules::join($baseName, $extension), $actor, false);
    }

    /**
     * Puts back the name the file had before the given rename, recording that as a revert.
     * The old name is not validated again: the file already carried it.
     *
     * @throws FileEditException history_not_found
     */
    public function revert(StoredFile $file, int $entryId, ?User $actor): ?FileNameChange
    {
        $entry = $this->repository()->findOneForFile($file, $entryId) ?? throw FileEditException::historyNotFound();
        return $this->apply($file, $entry->getOldName(), $actor, true);
    }

    /** @return list<FileNameChange> newest first */
    public function history(StoredFile $file, int $limit = 50): array
    {
        return $this->repository()->latestForFile($file, $limit);
    }

    private function apply(StoredFile $file, string $name, ?User $actor, bool $revert): ?FileNameChange
    {
        $current = (string) $file->getOriginalName();
        if ($name === $current) {
            return null;
        }
        $change = new FileNameChange($file, $current, $name, $actor, $revert);
        $file->setOriginalName($name);
        $this->em->persist($change);
        $this->em->flush();
        return $change;
    }

    private function repository(): FileNameChangeRepository
    {
        /** @var FileNameChangeRepository */
        return $this->em->getRepository(FileNameChange::class);
    }
}
