<?php

namespace App\Service;

use App\Entity\FileAlias;
use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Exception\FileEditException;
use App\Repository\FileAliasRepository;
use App\Repository\StoredFileRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Custom URLs (/u/{alias}): one per owned file, unique across all users, case sensitive. */
class FileAliasService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UrlGeneratorInterface $router
    ) {
    }

    public function aliasFor(StoredFile $file): ?string
    {
        return $this->repository()->findOneForFile($file)?->getAlias();
    }

    /**
     * @param iterable<StoredFile> $files
     * @return array<int, string> alias by file id, in one query
     */
    public function aliasesFor(iterable $files): array
    {
        $ids = [];
        foreach ($files as $file) {
            $ids[] = (int) $file->getId();
        }
        return $this->repository()->aliasesByFileId($ids);
    }

    /**
     * Gives the file this custom URL, replacing (and freeing) any previous one.
     *
     * @throws FileEditException alias_invalid | no_owner | alias_taken
     */
    public function set(StoredFile $file, string $alias): FileAlias
    {
        if (!preg_match(FileAlias::PATTERN, $alias)) {
            throw FileEditException::aliasInvalid();
        }
        $record = $this->em->getRepository(UploadRecord::class)->findOneBy(['image' => $file]) ?? throw FileEditException::noOwner();
        $current = $this->repository()->findOneForFile($file);
        if ($current && $current->getAlias() === $alias) {
            return $current;
        }
        if ($this->repository()->findOneByAlias($alias)) {
            throw FileEditException::aliasTaken();
        }
        if ($current) {
            $current->changeTo($alias);
        } else {
            $current = new FileAlias($record, $alias);
            $this->em->persist($current);
        }
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Claimed by someone else between the check and the write.
            throw FileEditException::aliasTaken();
        }
        return $current;
    }

    /** False when the file had no custom URL. */
    public function remove(StoredFile $file): bool
    {
        $current = $this->repository()->findOneForFile($file);
        if (!$current) {
            return false;
        }
        $this->em->remove($current);
        $this->em->flush();
        return true;
    }

    /** The file behind a custom URL, if it is currently served. */
    public function resolve(string $alias): ?StoredFile
    {
        if (!preg_match(FileAlias::PATTERN, $alias)) {
            return null;
        }
        /** @var StoredFileRepository $files */
        $files = $this->em->getRepository(StoredFile::class);
        return $files->findFileByAlias($alias);
    }

    public function url(string $alias): string
    {
        return $this->router->generate('file_alias', ['alias' => $alias], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function repository(): FileAliasRepository
    {
        /** @var FileAliasRepository */
        return $this->em->getRepository(FileAlias::class);
    }
}
