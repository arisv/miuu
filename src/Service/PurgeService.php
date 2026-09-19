<?php

namespace App\Service;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Repository\StoredFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * "Purge": queue every file a user owns for deletion. Files are only marked (the same
 * soft-delete the trash uses); the worker removes them for good once they are older than the
 * deletion pivot. Until then the purge can be cancelled, which restores every file.
 *
 * Cancelling is only offered while the purge is still in effect, i.e. every remaining file of
 * the user is marked. Once the user restores or uploads a single file, the state is no longer a
 * purge and files must be handled one by one again.
 *
 * Confirmation (the user's password, or the typed username for admins) is the caller's job.
 */
class PurgeService
{
    public function __construct(private EntityManagerInterface $em, private LoggerInterface $logger)
    {
    }

    /**
     * @return array{total: int, marked: int, active: int, pending: bool, can_cancel: bool}
     */
    public function status(User $user): array
    {
        return self::describe($this->repository()->purgeStats($user));
    }

    /**
     * Status for every user id that owns at least one file, for the admin list.
     * @return array<int, array{total: int, marked: int, active: int, pending: bool, can_cancel: bool}>
     */
    public function statusForAll(): array
    {
        $out = [];
        foreach ($this->repository()->purgeStatsForAll() as $userId => $stats) {
            $out[$userId] = self::describe($stats);
        }
        return $out;
    }

    /** Marks every unmarked file of the user; returns how many were newly marked. */
    public function purge(User $user, string $actor): int
    {
        // Bulk DQL update: StoredFile entities already loaded in this request keep their old
        // state in the identity map; status() re-reads from the database.
        $marked = $this->repository()->markUserFiles($user, new \DateTime());
        $this->logger->warning("Purge requested for user {$user->getId()} ({$user->getLogin()}) by {$actor}: {$marked} files marked for deletion");
        return $marked;
    }

    /**
     * Restores every file of the user. Only allowed while the purge is pending (all files marked).
     * @throws PurgeNotCancellable
     */
    public function cancel(User $user, string $actor): int
    {
        $status = $this->status($user);
        if (!$status['can_cancel']) {
            throw new PurgeNotCancellable($status);
        }
        $restored = $this->repository()->unmarkUserFiles($user);
        $this->logger->warning("Purge cancelled for user {$user->getId()} ({$user->getLogin()}) by {$actor}: {$restored} files restored");
        return $restored;
    }

    /** @param array{total: int, marked: int} $stats */
    public static function describe(array $stats): array
    {
        $total = (int) $stats['total'];
        $marked = (int) $stats['marked'];
        $pending = $total > 0 && $marked === $total;
        return [
            'total' => $total,
            'marked' => $marked,
            'active' => $total - $marked,
            'pending' => $pending,
            'can_cancel' => $pending,
        ];
    }

    private function repository(): StoredFileRepository
    {
        /** @var StoredFileRepository $repo */
        $repo = $this->em->getRepository(StoredFile::class);
        return $repo;
    }
}

class PurgeNotCancellable extends \RuntimeException
{
    /** @param array{total: int, marked: int, active: int, pending: bool, can_cancel: bool} $status */
    public function __construct(public readonly array $status)
    {
        parent::__construct(
            $status['total'] === 0
                ? 'There are no files to restore.'
                : "Not every file is queued for deletion ({$status['active']} of {$status['total']} still active), so there is no purge to cancel."
        );
    }
}
