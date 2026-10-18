<?php

namespace App\Repository;

use App\Entity\FileAlias;
use App\Entity\StoredFile;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<FileAlias> */
class FileAliasRepository extends EntityRepository
{
    public function findOneForFile(StoredFile $file): ?FileAlias
    {
        return $this->createQueryBuilder('a')
            ->join('a.uploadRecord', 'r')
            ->where('r.image = :file')
            ->setParameter('file', $file)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByAlias(string $alias): ?FileAlias
    {
        return $this->findOneBy(['alias' => $alias]);
    }

    /**
     * @param list<int> $fileIds
     * @return array<int, string> alias by file id, for files that have one
     */
    public function aliasesByFileId(array $fileIds): array
    {
        if (!$fileIds) {
            return [];
        }
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(r.image) AS fileId', 'a.alias')
            ->join('a.uploadRecord', 'r')
            ->where('r.image IN (:ids)')
            ->setParameter('ids', $fileIds)
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['fileId']] = $row['alias'];
        }
        return $map;
    }
}
