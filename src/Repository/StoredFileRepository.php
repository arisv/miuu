<?php

namespace App\Repository;

use App\Service\ListOrder;
use App\Service\ListOrdering;
use App\Service\NameSearch;
use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;

class StoredFileRepository extends EntityRepository
{
    /**
     * Files of a user with a pending purge do not exist for anyone: every lookup by URL applies
     * this, so direct links, thumbnails, the view page and the delete page all answer 404.
     */
    private const NOT_PURGING = 'NOT EXISTS (SELECT 1 FROM App\Entity\UploadRecord pl JOIN pl.user pu'
        . ' WHERE pl.image = f AND pu.purgeTs IS NOT NULL)';

    public function findFileByCustomURL($customUrl)
    {
        $qb = $this->createQueryBuilder('f')
            ->where('f.customUrl = :url')
            ->setParameter('url', $customUrl)
            ->andWhere('f.visibilityStatus = true')
            ->andWhere(self::NOT_PURGING);
        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findFileByCustomURLAnyVisibility($customUrl)
    {
        return $this->createQueryBuilder('f')
            ->where('f.customUrl = :url')
            ->setParameter('url', $customUrl)
            ->andWhere(self::NOT_PURGING)
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

    // ---- Purge ---------------------------------------------------------------------------------

    /** Files owned by the user (through upload records), regardless of trash state. */
    public function countUserFiles(User $user): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(file.id)')
            ->from('App\Entity\UploadRecord', 'log')
            ->join('log.image', 'file')
            ->where('log.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<int, int> file count keyed by user id, for the admin list */
    public function countFilesByUser(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(log.user) AS userId, COUNT(file.id) AS total')
            ->from('App\Entity\UploadRecord', 'log')
            ->join('log.image', 'file')
            ->where('log.user IS NOT NULL')
            ->groupBy('log.user')
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['userId']] = (int) $row['total'];
        }
        return $out;
    }

    /** One batch of the user's files for the purge command, oldest first. @return StoredFile[] */
    public function userFilesBatch(User $user, int $limit): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('file')
            ->from('App\Entity\StoredFile', 'file')
            ->where('file.id IN (SELECT IDENTITY(l.image) FROM App\Entity\UploadRecord l WHERE l.user = :user)')
            ->setParameter('user', $user)
            ->orderBy('file.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Whether the file belongs to a user with a pending purge. */
    public function ownerIsPurging(StoredFile $file): bool
    {
        $ts = $this->getEntityManager()->createQuery(
            'SELECT u.purgeTs FROM App\Entity\UploadRecord l JOIN l.user u WHERE l.image = :file'
        )->setParameter('file', $file)->setMaxResults(1)->getOneOrNullResult();
        return $ts !== null && $ts['purgeTs'] !== null;
    }

    /**
     * A file of the user with the given content in the same year-month storage bucket as $timestamp,
     * trashed or not; internal names are "<sha1>_<ts>".
     */
    public function findUserFileInBucket(User $user, string $sha1, int $timestamp): ?StoredFile
    {
        // Buckets are formatted in the PHP zone, like StoredFile::storageSubdirectory().
        $start = (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->modify('first day of this month midnight');
        return $this->getEntityManager()->createQueryBuilder()
            ->select('file')
            ->from('App\Entity\StoredFile', 'file')
            ->join('App\Entity\UploadRecord', 'log', Expr\Join::WITH, 'log.image = file')
            ->where('log.user = :user')
            ->andWhere('file.internalName LIKE :prefix')
            ->andWhere('file.date >= :start AND file.date < :end')
            ->setParameter('user', $user)
            ->setParameter('prefix', $sha1 . '_%')
            ->setParameter('start', $start->getTimestamp())
            ->setParameter('end', $start->modify('+1 month')->getTimestamp())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
        if (isset($filter['q'])) {
            // Every word must match somewhere in the name; see NameSearch for the pattern rules.
            foreach (NameSearch::terms($filter['q']) as $i => $word) {
                $qb->andWhere(sprintf("file.originalName LIKE :q_%d ESCAPE '%s'", $i, NameSearch::ESCAPE))
                    ->setParameter('q_' . $i, NameSearch::likePattern($word));
            }
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
