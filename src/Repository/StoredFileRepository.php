<?php

namespace App\Repository;

use App\Entity\UploadRecord;
use App\Entity\User;
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

    public function getUserUploadHistoryPage(User $user, $beforeOffset, $afterOffset, $limit)
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('log, file')
            ->from('App\Entity\UploadRecord', 'log')
            ->leftJoin('log.image', 'file')
            ->where('log.user = :user')
            ->setParameter('user', $user);
        if ($afterOffset > 0) {
            $qb->andWhere('log.uploadId < :offset');
            $qb->setParameter('offset', $afterOffset);
        } else if ($beforeOffset > 0) {
            $qb->andWhere('log.uploadId > :offset');
            $qb->setParameter('offset', $beforeOffset);
        }
        $qb->setMaxResults($limit + 1);

        if ($beforeOffset > 0) {
            $qb->orderBy('log.uploadId', 'ASC');
        } else {
            $qb->orderBy('log.uploadId', 'DESC');
        }

        return $qb->getQuery()->getResult();

    }
}