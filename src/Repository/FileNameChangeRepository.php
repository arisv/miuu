<?php

namespace App\Repository;

use App\Entity\FileNameChange;
use App\Entity\StoredFile;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<FileNameChange> */
class FileNameChangeRepository extends EntityRepository
{
    /** @return list<FileNameChange> newest first */
    public function latestForFile(StoredFile $file, int $limit): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('u')
            ->leftJoin('c.renamedBy', 'u')
            ->where('c.file = :file')
            ->setParameter('file', $file)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneForFile(StoredFile $file, int $id): ?FileNameChange
    {
        return $this->createQueryBuilder('c')
            ->where('c.file = :file')
            ->andWhere('c.id = :id')
            ->setParameter('file', $file)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
