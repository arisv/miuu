<?php

namespace App\Repository;

use App\Entity\UploadRecord;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr;

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

    public function getUserUploadHistoryPage(User $user, $cursor, $limit, $orderBy, $filter)
    {
        $resolve = function ($symbol) {
            if ($symbol == "<") {
                return ["lt", "lte"];
            } else if ($symbol == ">") {
                return ["gt", "gte"];
            } else {
                return ["eq", "eq"];
            }
        };

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('log, file')
            ->from('App\Entity\UploadRecord', 'log')
            ->leftJoin('log.image', 'file')
            ->where('log.user = :user')
            ->setParameter('user', $user);
        $qb->setMaxResults($limit + 1);

        if (!$orderBy) {
            $orderBy = ['file.date' => [
                'op' => '<',
                'order' => 'DESC'
            ]];
        }

        if (isset($filter['calendar'])) {
            [$calendarStart, $calendarEnd] = $filter['calendar'];
            $calendarEnd->modify('last day of this month');
            $qb->andWhere('file.date > :start')
                ->andWhere('file.date < :end')
                ->setParameter('start', $calendarStart->getTimestamp())
                ->setParameter('end', $calendarEnd->getTimestamp());
        }

        $firstSortColumn = array_key_first($orderBy);
        $firstSort = $orderBy[$firstSortColumn];

        if ($cursor) {
            $apply = function ($orderBy) use (&$apply, &$qb, $cursor, $resolve, $firstSortColumn, $firstSort) {
                $column = array_key_first($orderBy);
                $columnData = array_shift($orderBy);
                $placeholder = str_replace('.', '', $column);
                [$strict, $equals] = $resolve($columnData['op']);
                if (empty($orderBy)) {
                    [$firstStrict, $firstEquals] = $resolve($firstSort['op']);
                    $expr = $qb->expr()->andX(
                        $qb->expr()->$equals($column, ':col_' . $placeholder),
                        $qb->expr()->andX(
                            $qb->expr()->orX(
                                $qb->expr()->$strict($column, ':col_' . $placeholder),
                                $qb->expr()->$firstStrict('log.uploadId', ':col_log_upload_id')
                            )
                        )
                    );
                    $qb->setParameter('col_' . $placeholder, $cursor[$column]);
                    $qb->setParameter('col_log_upload_id', $cursor['log.uploadId']);
                } else {
                    $expr = $qb->expr()->andX(
                        $qb->expr()->$equals($column, ':col_' . $placeholder),
                        $qb->expr()->andX(
                            $qb->expr()->orX(
                                $qb->expr()->$strict($column, ':col_' . $placeholder),
                                $apply($orderBy)
                            )
                        )
                    );
                    $qb->setParameter('col_' . $placeholder, $cursor[$column]);
                }
                return $expr;
            };
            $qb->andWhere($apply($orderBy));
        }


        foreach ($orderBy as $column => $orderDirective) {
            $qb->addOrderBy($column, $orderDirective['order']);
        }
        $qb->addOrderBy('log.uploadId', $firstSort['order']);

        return $qb->getQuery()->getResult();

    }
}