<?php

namespace App\Command;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Repository\StoredFileRepository;
use App\Service\FileService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Destroys the files of every user whose purge time has come (see PurgeService), then clears
 * their purge_ts. Meant to run from the systemd timer every 10 minutes (deploy/systemd).
 *
 * Files go in batches; one database lock per user (`miu-purge-<login>`, 2 minute TTL, refreshed
 * after every batch) keeps two overlapping runs off the same user while letting the later run
 * pick up other due users. A crashed run only stalls its user until the lock expires.
 */
#[AsCommand(name: 'app:purge', description: 'Delete the files of users whose purge grace period has passed')]
class PurgeCommand extends Command
{
    public const LOCK_PREFIX = 'miu-purge-';
    public const LOCK_TTL = 120.0;
    public const DEFAULT_BATCH = 1000;

    public function __construct(
        private EntityManagerInterface $em,
        private FileService $files,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Files deleted per batch (and per lock refresh)', self::DEFAULT_BATCH)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted without touching anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var StoredFileRepository $files */
        $files = $this->em->getRepository(StoredFile::class);

        $due = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.purgeTs IS NOT NULL AND u.purgeTs <= :now')
            ->setParameter('now', time())
            ->orderBy('u.purgeTs', 'ASC')
            ->getQuery()
            ->getResult();
        if (!$due) {
            $io->writeln('No purge due.', OutputInterface::VERBOSITY_VERBOSE);
            return Command::SUCCESS;
        }

        /** @var User $user */
        foreach ($due as $user) {
            $userId = $user->getId();
            $login = $user->getLogin();
            if ($dryRun) {
                $io->writeln(sprintf('[dry-run] %s (#%d): %d files would be deleted', $login, $userId, $files->countUserFiles($user)));
                continue;
            }
            $lock = $this->lockFactory->createLock(self::LOCK_PREFIX . $login, self::LOCK_TTL);
            if (!$lock->acquire()) {
                $io->writeln("{$login}: another run is purging this user, skipping.", OutputInterface::VERBOSITY_VERBOSE);
                continue;
            }
            try {
                // Start from an empty identity map: upload records loaded earlier would otherwise
                // still reference the files being removed.
                $this->em->clear();
                $deleted = 0;
                $missing = 0;
                while (true) {
                    // A cancel that landed meanwhile stops the run before the next batch.
                    $fresh = $this->em->getRepository(User::class)->find($userId);
                    if ($fresh === null || !$fresh->isPurging()) {
                        $io->writeln("{$login}: purge cancelled meanwhile, stopping after {$deleted} files.");
                        $this->logger->warning("Purge of user {$userId} ({$login}) cancelled mid-run after {$deleted} files");
                        $deleted = -1;
                        break;
                    }
                    $batch = $files->userFilesBatch($fresh, $batchSize);
                    if (!$batch) {
                        break;
                    }
                    // Upload records go first, explicitly: the database cascade would do it on
                    // MariaDB, but not every engine enforces foreign keys.
                    $this->em->createQuery('DELETE App\Entity\UploadRecord l WHERE l.image IN (:files)')
                        ->setParameter('files', $batch)
                        ->execute();
                    foreach ($batch as $file) {
                        $report = $this->files->deleteFileFromStorage($file);
                        $deleted++;
                        if ($report['status'] === FileService::DELETED_NOT_ON_DISK) {
                            $missing++;
                        }
                    }
                    // Keep memory flat across large libraries; entities are re-read per batch anyway.
                    $this->em->clear();
                    $io->writeln("{$login}: {$deleted} files deleted so far", OutputInterface::VERBOSITY_VERBOSE);
                    // Always back to the full TTL: a batch may start with seconds left and take longer.
                    $lock->refresh();
                }
                if ($deleted >= 0) {
                    $fresh = $this->em->getRepository(User::class)->find($userId);
                    if ($fresh !== null && $fresh->isPurging()) {
                        $fresh->setPurgeTs(null);
                        $this->em->flush();
                    }
                    $io->writeln("{$login}: purge complete, {$deleted} files deleted ({$missing} were already missing on disk).");
                    $this->logger->warning("Purge of user {$userId} ({$login}) complete: {$deleted} files deleted, {$missing} missing on disk");
                }
            } finally {
                $lock->release();
            }
        }
        return Command::SUCCESS;
    }
}
