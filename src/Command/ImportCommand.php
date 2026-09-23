<?php

namespace App\Command;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Exception\UploadBlockedException;
use App\Repository\StoredFileRepository;
use App\Service\FileService;
use App\Service\MediaDateExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Mime\MimeTypes;

/**
 * Imports a directory tree into a user's library, each file dated by its EXIF / video metadata or
 * mtime instead of the import time. The source tree is only read. A file is skipped when the user
 * has the same content in the same year-month bucket: re-runs skip what is done, while a copy
 * uploaded in another month does not hide the original. Failing files are reported and skipped.
 */
#[AsCommand(name: 'app:import', description: 'Import a directory of files into a user\'s library, backdated from their metadata')]
class ImportCommand extends Command
{
    private const CLEAR_EVERY = 50;
    private const REPORT_COLUMNS = ['path', 'sha1', 'date', 'source', 'status', 'file_id', 'custom_url', 'error'];

    public function __construct(
        private EntityManagerInterface $em,
        private FileService $files,
        private MediaDateExtractor $dates,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dir', InputArgument::REQUIRED, 'Directory to import, walked recursively (dotfiles are ignored)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Login of the user who will own the files')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Zone for EXIF times that carry no offset', date_default_timezone_get())
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print every file with its computed date and source; store nothing')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Write a CSV line per file to this path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = rtrim((string) $input->getArgument('dir'), '/');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_dir($dir)) {
            $io->error("Not a directory: {$dir}");
            return Command::INVALID;
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => (string) $input->getOption('user')]);
        if (!$user instanceof User) {
            $io->error('Pass the owner with --user=<login>; no such user.');
            return Command::INVALID;
        }
        if ($user->isPurging()) {
            $io->error(UploadBlockedException::MESSAGE);
            return Command::FAILURE;
        }
        try {
            $tz = new \DateTimeZone((string) $input->getOption('timezone'));
        } catch (\Exception) {
            $io->error('Unknown timezone: ' . $input->getOption('timezone'));
            return Command::INVALID;
        }
        if (!MediaDateExtractor::exifAvailable()) {
            $io->warning('The exif PHP extension is missing: images will be dated by mtime.');
        }
        $report = null;
        if ($input->getOption('report')) {
            $report = fopen((string) $input->getOption('report'), 'w');
            if (!$report) {
                $io->error('Cannot write the report to ' . $input->getOption('report'));
                return Command::INVALID;
            }
            fputcsv($report, self::REPORT_COLUMNS, escape: '');
        }

        $paths = [];
        foreach (Finder::create()->files()->in($dir)->ignoreDotFiles(true)->sortByName() as $found) {
            $paths[] = $found->getPathname();
        }

        $userId = $user->getId();
        $mimeTypes = MimeTypes::getDefault();
        /** @var StoredFileRepository $repository */
        $repository = $this->em->getRepository(StoredFile::class);
        $seen = [];
        $counts = ['imported' => 0, 'duplicate' => 0, 'failed' => 0];
        $sources = [];

        $io->writeln(sprintf('%s %d files for %s, naive EXIF times in %s.', $dryRun ? '[dry-run] Checking' : 'Importing', count($paths), $user->getLogin(), $tz->getName()));
        // A bar can only be erased on a terminal; piped output (dry-run log to a file) gets none.
        $bar = $output->isDecorated() ? $io->createProgressBar(count($paths)) : new ProgressBar(new NullOutput(), count($paths));
        foreach ($paths as $i => $path) {
            $row = ['path' => substr($path, strlen($dir) + 1), 'sha1' => '', 'date' => '', 'source' => '', 'status' => '', 'file_id' => '', 'custom_url' => '', 'error' => ''];
            try {
                $sha = @sha1_file($path);
                if ($sha === false) {
                    throw new \RuntimeException('unreadable');
                }
                $row['sha1'] = $sha;
                [$ts, $source] = $this->dates->extract($path, $mimeTypes->guessMimeType($path) ?? '', $tz);
                $row['date'] = gmdate('c', $ts);
                $row['source'] = $source;
                $key = $sha . '@' . date('Y-m', $ts);
                // The set catches repeats within this run, which a dry run never writes to the database.
                if (isset($seen[$key]) || $repository->findUserFileInBucket($user, $sha, $ts)) {
                    $row['status'] = 'duplicate';
                } else {
                    if (!$dryRun) {
                        $stored = $this->files->importLocalFile($path, $user, $ts);
                        $row['file_id'] = $stored->getId();
                        $row['custom_url'] = $stored->getCustomUrl();
                    }
                    $row['status'] = $dryRun ? 'would-import' : 'imported';
                    $sources[$source] = ($sources[$source] ?? 0) + 1;
                }
                $seen[$key] = true;
            } catch (UploadBlockedException $e) {
                $bar->clear();
                $io->error($e->getMessage());
                return Command::FAILURE;
            } catch (\Throwable $e) {
                $row['status'] = 'failed';
                $row['error'] = $e->getMessage();
                if (!$dryRun) {
                    $bar->clear();
                    $io->writeln("<error>Failed</error> {$row['path']}: {$e->getMessage()}");
                    $bar->display();
                }
            }
            $counts[$row['status'] === 'would-import' ? 'imported' : $row['status']]++;
            if ($report) {
                fputcsv($report, array_values($row), escape: '');
            }
            if (($i + 1) % self::CLEAR_EVERY === 0) {
                // Keep memory flat over thousands of files; the owner is re-read after each clear.
                $this->em->clear();
                $user = $this->em->getRepository(User::class)->find($userId);
            }
            if ($dryRun) {
                $bar->clear();
                $io->writeln(self::dryRunLine($row, $tz));
            }
            $bar->advance();
        }
        $bar->finish();
        if ($output->isDecorated()) {
            $io->newLine(2);
        }
        if ($report) {
            fclose($report);
        }

        ksort($sources);
        $io->definitionList(
            [$dryRun ? 'Would import' : 'Imported' => $counts['imported']],
            ['Skipped (duplicate)' => $counts['duplicate']],
            ['Failed' => $counts['failed']],
            ['Dated by' => $sources ? implode(', ', array_map(fn ($s, $n) => "{$s} {$n}", array_keys($sources), $sources)) : '-'],
        );
        // Per-file failures are listed above and in the report; they do not fail the run.
        return Command::SUCCESS;
    }

    /**
     * "2011-02-03 04:05:06 +01:00  exif   2011/trip/beach.jpg", with the date shown in --timezone.
     * @param array<string, string|int> $row
     */
    private static function dryRunLine(array $row, \DateTimeZone $tz): string
    {
        $date = $row['date'] === '' ? '-' : (new \DateTimeImmutable($row['date']))->setTimezone($tz)->format('Y-m-d H:i:s P');
        $how = $row['status'] === 'would-import' ? $row['source'] : $row['status'];
        $line = sprintf('%-26s %-9s %s', $date, $how, $row['path']);
        return $row['error'] === '' ? $line : "{$line}  ({$row['error']})";
    }
}
