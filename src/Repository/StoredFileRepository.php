<?php

namespace App\Repository;

use App\Service\ListOrder;
use App\Service\ListOrdering;
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

    public function getUserUploadHistoryPage(User $user, array $cursor, $limit, ListOrder $orderBy, $filter)
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
    public function getAnonymousUploadHistoryPage(array $cursor, $limit, ListOrder $orderBy, $filter)
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('file')
            ->from('App\Entity\StoredFile', 'file')
            ->leftJoin('App\Entity\UploadRecord', 'log', Expr\Join::WITH, 'log.image = file')
            ->where('log.uploadId IS NULL');

        return $this->fetchHistoryPage($qb, $cursor, $limit, $orderBy, $filter, 'file.id');
    }

    /** Number of files in the user's history that match the filter (calendar range). */
    public function countUserUploadHistory(User $user, $filter): int
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(log.uploadId)')
            ->from('App\Entity\UploadRecord', 'log')
            ->leftJoin('log.image', 'file')
            ->where('log.user = :user')
            ->setParameter('user', $user);
        $this->applyFilter($qb, $filter);
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countAnonymousUploadHistory($filter): int
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(file.id)')
            ->from('App\Entity\StoredFile', 'file')
            ->leftJoin('App\Entity\UploadRecord', 'log', Expr\Join::WITH, 'log.image = file')
            ->where('log.uploadId IS NULL');
        $this->applyFilter($qb, $filter);
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // ---- Purge (queue all of a user's files for deletion) -------------------------------------

    /** @return array{total: int, marked: int} */
    public function purgeStats(User $user): array
    {
        $row = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(file.id) AS total, SUM(CASE WHEN file.markedForDeletionAt IS NULL THEN 0 ELSE 1 END) AS marked')
            ->from('App\Entity\UploadRecord', 'log')
            ->join('log.image', 'file')
            ->where('log.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleResult();
        return ['total' => (int) $row['total'], 'marked' => (int) $row['marked']];
    }

    /** @return array<int, array{total: int, marked: int}> keyed by user id */
    public function purgeStatsForAll(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(log.user) AS userId, COUNT(file.id) AS total, SUM(CASE WHEN file.markedForDeletionAt IS NULL THEN 0 ELSE 1 END) AS marked')
            ->from('App\Entity\UploadRecord', 'log')
            ->join('log.image', 'file')
            ->where('log.user IS NOT NULL')
            ->groupBy('log.user')
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['userId']] = ['total' => (int) $row['total'], 'marked' => (int) $row['marked']];
        }
        return $out;
    }

    /** Marks every not-yet-marked file of the user; returns the number of rows changed. */
    public function markUserFiles(User $user, \DateTimeInterface $at): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\StoredFile f SET f.markedForDeletionAt = :at, f.visibilityStatus = false'
            . ' WHERE f.markedForDeletionAt IS NULL'
            . ' AND f.id IN (SELECT IDENTITY(l.image) FROM App\Entity\UploadRecord l WHERE l.user = :user)'
        )->setParameter('at', $at)->setParameter('user', $user)->execute();
    }

    /** Clears the deletion mark on every file of the user; returns the number of rows changed. */
    public function unmarkUserFiles(User $user): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\StoredFile f SET f.markedForDeletionAt = NULL, f.visibilityStatus = true'
            . ' WHERE f.markedForDeletionAt IS NOT NULL'
            . ' AND f.id IN (SELECT IDENTITY(l.image) FROM App\Entity\UploadRecord l WHERE l.user = :user)'
        )->setParameter('user', $user)->execute();
    }

    private function applyFilter(QueryBuilder $qb, $filter): void
    {
        if (isset($filter['calendar'])) {
            [$calendarStart, $calendarEnd] = $filter['calendar'];
            $calendarEnd = (clone $calendarEnd)->modify('last day of this month')->setTime(23, 59, 59);
            $qb->andWhere('file.date >= :start')
                ->andWhere('file.date <= :end')
                ->setParameter('start', $calendarStart->getTimestamp())
                ->setParameter('end', $calendarEnd->getTimestamp());
        }
    }

    /**
     * Shared keyset pagination, ordering and calendar filtering. The query builder must expose
     * the file under the "file" alias; $tieBreaker is the unique column used to break ties.
     *
     * Ordering is a list of expressions (group key, then sort key, then the tie-breaker), all in
     * the same direction. The cursor carries the raw column values of the last row; the keyset
     * predicate is the usual lexicographic comparison:
     *   k1 > v1 OR (k1 = v1 AND k2 > v2) OR (k1 = v1 AND k2 = v2 AND tie > vt)
     */
    private function fetchHistoryPage(QueryBuilder $qb, array $cursor, $limit, ListOrder $order, $filter, string $tieBreaker)
    {
        $qb->setMaxResults($limit + 1);
        $this->applyFilter($qb, $filter);

        $direction = $order->descending() ? 'DESC' : 'ASC';
        $strict = $order->descending() ? '<' : '>';
        $keys = ListOrdering::orderKeys($order);
        $keys[] = ['expr' => $tieBreaker, 'key' => 'tie'];

        if ($cursor) {
            $values = ListOrdering::cursorValues($order, $cursor);
            $values['tie'] = $cursor['i'];
            $branches = [];
            foreach ($keys as $depth => $key) {
                $terms = [];
                foreach (array_slice($keys, 0, $depth) as $prev) {
                    $terms[] = sprintf('%s = :k_%s', $prev['expr'], $prev['key']);
                }
                $terms[] = sprintf('%s %s :k_%s', $key['expr'], $strict, $key['key']);
                $branches[] = '(' . implode(' AND ', $terms) . ')';
            }
            $qb->andWhere('(' . implode(' OR ', $branches) . ')');
            foreach ($keys as $key) {
                $qb->setParameter('k_' . $key['key'], $values[$key['key']]);
            }
        }

        foreach ($keys as $key) {
            $qb->addOrderBy($key['expr'], $direction);
        }

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
