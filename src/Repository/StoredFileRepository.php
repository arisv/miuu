<?php

namespace App\Repository;

use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;

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

    public function findFileByCustomURLAnyVisibility($customUrl)
    {
        return $this->createQueryBuilder('f')
            ->where('f.customUrl = :url')
            ->setParameter('url', $customUrl)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getUserUploadHistoryPage(User $user, $cursor, $limit, $orderBy, $filter)
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('log, file')
            ->from('App\Entity\UploadRecord', 'log')
            ->leftJoin('log.image', 'file')
            ->where('log.user = :user')
            ->setParameter('user', $user);

        return $this->fetchHistoryPage($qb, $cursor, $limit, $orderBy, $filter, 'log.uploadId');
    }

    /**
     * Files that have no upload record at all, i.e. were uploaded without being logged in.
     */
    public function getAnonymousUploadHistoryPage($cursor, $limit, $orderBy, $filter)
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('file')
            ->from('App\Entity\StoredFile', 'file')
            ->leftJoin('App\Entity\UploadRecord', 'log', Expr\Join::WITH, 'log.image = file')
            ->where('log.uploadId IS NULL');

        return $this->fetchHistoryPage($qb, $cursor, $limit, $orderBy, $filter, 'file.id');
    }

    /**
     * Shared cursor pagination, ordering and calendar filtering. The query builder must expose
     * the file under the "file" alias; $tieBreaker is the unique column used to break ordering ties.
     */
    private function fetchHistoryPage(QueryBuilder $qb, $cursor, $limit, $orderBy, $filter, string $tieBreaker)
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
            $apply = function ($orderBy) use (&$apply, &$qb, $cursor, $resolve, $firstSort, $tieBreaker) {
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
                                $qb->expr()->$firstStrict($tieBreaker, ':col_tiebreaker')
                            )
                        )
                    );
                    $qb->setParameter('col_' . $placeholder, $cursor[$column]);
                    $qb->setParameter('col_tiebreaker', $cursor[$tieBreaker]);
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
        $qb->addOrderBy($tieBreaker, $firstSort['order']);

        return $qb->getQuery()->getResult();
    }

    public function allFilesGenerator()
    {
        $qb = $this->createQueryBuilder("file", "file.id");
        $qb->addOrderBy("file.id")
            ->andWhere("file.id > :lastId");
        $query = $qb->getQuery();
        $query->setMaxResults(300);
        $lastId = 0;
        do {
            $query->setParameter('lastId', $lastId);
            $batch = $query->getResult();
            $ids = array_keys($batch);
            $lastId = end($ids);
            yield $batch;
            $this->getEntityManager()->clear();
        } while (!empty($batch));
    }

    public function countTotalProducts()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select($qb->expr()->count("file"))
            ->from(StoredFile::class, "file");
        return $qb->getQuery()->getSingleScalarResult();
    }
}
