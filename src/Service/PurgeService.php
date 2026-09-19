<?php

namespace App\Service;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Repository\StoredFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * "Purge": delete every file a user owns, after a grace period.
 *
 * A purge is state on the user: `users.purge_ts` is the unix time from which the files may be
 * destroyed (now + PURGE_GRACE_TIME minutes when requested). While it is set the user is frozen:
 * their files answer 404 everywhere, their library is empty and uploads are refused. The
 * `app:purge` command destroys the files once the time has come and clears the timestamp.
 * Cancelling before that simply clears the timestamp and everything reappears. Files a running
 * command has already destroyed stay gone; it stops at the next batch once it sees the cancel.
 *
 * Confirmation (the user's password, or the typed username for admins) is the caller's job.
 */
class PurgeService
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private int $purgeGraceMinutes,
    ) {
    }

    /**
     * @return array{pending: bool, purge_at: ?string, total: int, marked: int, active: int, can_cancel: bool}
     */
    public function status(User $user): array
    {
        return self::describe($user, $this->repository()->countUserFiles($user));
    }

    /**
     * Status for every user with files or a pending purge, keyed by user id (admin list).
     * @return array<int, array{pending: bool, purge_at: ?string, total: int, marked: int, active: int, can_cancel: bool}>
     */
    public function statusForAll(): array
    {
        $counts = $this->repository()->countFilesByUser();
        $out = [];
        /** @var User $user */
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $total = $counts[$user->getId()] ?? 0;
            if ($total > 0 || $user->isPurging()) {
                $out[$user->getId()] = self::describe($user, $total);
            }
        }
        return $out;
    }

    /** Schedules the purge; a second request while one is pending changes nothing. */
    public function purge(User $user, string $actor): array
    {
        if (!$user->isPurging()) {
            $user->setPurgeTs(time() + $this->purgeGraceMinutes * 60);
            $this->em->flush();
            $this->logger->warning(sprintf(
                'Purge scheduled for user %d (%s) by %s: %d files, due %s',
                $user->getId(), $user->getLogin(), $actor,
                $this->repository()->countUserFiles($user), $user->purgeDueAt()->format(DATE_ATOM)
            ));
        }
        return $this->status($user);
    }

    /**
     * Cancels a pending purge. Files already destroyed by a purge run in progress are not recoverable.
     * @throws PurgeNotCancellable when no purge is pending
     */
    public function cancel(User $user, string $actor): array
    {
        if (!$user->isPurging()) {
            throw new PurgeNotCancellable();
        }
        $user->setPurgeTs(null);
        $this->em->flush();
        $this->logger->warning("Purge cancelled for user {$user->getId()} ({$user->getLogin()}) by {$actor}");
        return $this->status($user);
    }

    /**
     * `marked`/`active` are kept for older app builds: while a purge is pending every file counts
     * as marked, otherwise every file is active.
     */
    public static function describe(User $user, int $total): array
    {
        $pending = $user->isPurging();
        return [
            'pending' => $pending,
            'purge_at' => $user->purgeDueAt()?->format(DATE_ATOM),
            'total' => $total,
            'marked' => $pending ? $total : 0,
            'active' => $pending ? 0 : $total,
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
    public function __construct()
    {
        parent::__construct('No purge is pending.');
    }
}
