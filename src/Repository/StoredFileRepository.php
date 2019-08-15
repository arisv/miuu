<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;

class StoredFileRepository extends EntityRepository
{
    public function findFileByCustomURL($customUrl)
    {
        $qb = $this->createQueryBuilder('f')
            ->where('f.customUrl = :url')
            ->setParameter('url', $customUrl)
            ->andWhere('f.visibilityStatus = true');
        return $qb->getQuery()->getOneOrNullResult();
    }
}