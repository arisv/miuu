<?php

namespace App\Tests\Command;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Service\FileService;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `app:import` stores a directory tree for a user, each file dated by its metadata (or mtime),
 * skips content the user already has in the same year-month bucket, and never touches the source tree.
 */
final class ImportCommandTest extends KernelTestCase
{
    private const MEDIA = __DIR__ . '/../Fixtures/media/';

    private EntityManagerInterface $em;
    private PurgeFixtures $fixtures;
    private CommandTester $tester;
    private Filesystem $fs;
    private string $source;
    private string $storage;
    private User $bob;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->fixtures = new PurgeFixtures($this->em, $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $this->bob = $this->fixtures->user('bob', 'bob12345');
        $this->tester = new CommandTester((new Application(self::$kernel))->find('app:import'));

        $this->fs = new Filesystem();
        $this->storage = $container->getParameter('kernel.project_dir') . '/' . $_ENV['STORAGE_DIR'];
        $this->fs->remove($this->storage);
        $this->source = sys_get_temp_dir() . '/miu-import-' . bin2hex(random_bytes(4));
        $this->fs->mkdir($this->source . '/2011/trip');
        $this->fs->copy(self::MEDIA . 'exif-offset.jpg', $this->source . '/2014.jpg');
        $this->fs->copy(self::MEDIA . 'exif-naive.jpg', $this->source . '/2011/trip/beach.jpg');
        file_put_contents($this->source . '/2011/notes.txt', 'written in march 2015');
        touch($this->source . '/2011/notes.txt', gmmktime(12, 0, 0, 3, 10, 2015));
        file_put_contents($this->source . '/.DS_Store', 'junk');
    }

    protected function tearDown(): void
    {
        $this->fs->remove([$this->source, $this->storage]);
        parent::tearDown();
    }

    private function import(array $extra = []): int
    {
        return $this->tester->execute(['dir' => $this->source, '--user' => 'bob', '--timezone' => 'Europe/Berlin'] + $extra);
    }

    /** @return array<string, StoredFile> bob's files keyed by original name */
    private function bobsFiles(): array
    {
        $out = [];
        foreach ($this->fixtures->filesOf($this->bob) as $file) {
            $out[$file->getOriginalName()] = $file;
        }
        ksort($out);
        return $out;
    }

    public function testFilesAreStoredUnderTheirMetadataDate(): void
    {
        self::assertSame(Command::SUCCESS, $this->import(), $this->tester->getDisplay());

        $files = $this->bobsFiles();
        self::assertSame(['2014.jpg', 'beach.jpg', 'notes.txt'], array_keys($files), '.DS_Store is ignored');
        self::assertSame((new \DateTimeImmutable('2014-07-05 18:30:00+09:00'))->getTimestamp(), $files['2014.jpg']->getDate());
        self::assertSame((new \DateTimeImmutable('2011-02-03 04:05:06', new \DateTimeZone('Europe/Berlin')))->getTimestamp(), $files['beach.jpg']->getDate());
        self::assertSame(gmmktime(12, 0, 0, 3, 10, 2015), $files['notes.txt']->getDate());
        foreach ($files as $file) {
            self::assertFileExists($this->storage . '/' . $file->relativePath());
        }
        self::assertStringStartsWith('2015-03/', $files['notes.txt']->relativePath());
        self::assertStringContainsString('exif 2, mtime 1', $this->tester->getDisplay());

        self::assertFileEquals(self::MEDIA . 'exif-offset.jpg', $this->source . '/2014.jpg', 'source left in place');
        self::assertSame(gmmktime(12, 0, 0, 3, 10, 2015), filemtime($this->source . '/2011/notes.txt'));
    }

    public function testDuplicatesAreSkippedWithinARunAndOnReruns(): void
    {
        $this->fs->copy(self::MEDIA . 'exif-naive.jpg', $this->source . '/2011/beach-copy.jpg');

        $this->import();
        self::assertCount(3, $this->bobsFiles(), $this->tester->getDisplay());
        self::assertMatchesRegularExpression('/Skipped \(duplicate\)\s+1/', $this->tester->getDisplay());

        self::assertSame(Command::SUCCESS, $this->import());
        self::assertCount(3, $this->bobsFiles());
        self::assertMatchesRegularExpression('/Imported\s+0/', $this->tester->getDisplay());
        self::assertMatchesRegularExpression('/Skipped \(duplicate\)\s+4/', $this->tester->getDisplay());
    }

    public function testTheSameContentInAnotherMonthIsNotADuplicate(): void
    {
        // Uploaded years later, so it sits in the 2019-06 bucket; the archive original belongs in 2014-07.
        static::getContainer()->get(FileService::class)->importLocalFile(self::MEDIA . 'exif-offset.jpg', $this->bob, gmmktime(12, 0, 0, 6, 1, 2019));
        // Same bytes, different mtimes: one copy per month.
        file_put_contents($this->source . '/2011/notes-copy.txt', 'written in march 2015');
        touch($this->source . '/2011/notes-copy.txt', gmmktime(12, 0, 0, 4, 10, 2015));

        $this->import();

        $display = $this->tester->getDisplay();
        self::assertMatchesRegularExpression('/Imported\s+4/', $display);
        self::assertMatchesRegularExpression('/Skipped \(duplicate\)\s+0/', $display);
        $months = array_map(fn (StoredFile $f) => $f->storageSubdirectory(), $this->fixtures->filesOf($this->bob));
        sort($months);
        self::assertSame(['2011-02', '2014-07', '2015-03', '2015-04', '2019-06'], $months);
    }

    public function testDryRunLogsEveryFileAndStoresNothing(): void
    {
        $this->fs->copy(self::MEDIA . 'exif-naive.jpg', $this->source . '/2011/trip/beach-copy.jpg');

        $this->import(['--dry-run' => true]);

        $display = $this->tester->getDisplay();
        self::assertMatchesRegularExpression('#2015-03-10 13:00:00 \+01:00\s+mtime\s+2011/notes.txt#', $display, 'dates shown in --timezone');
        self::assertMatchesRegularExpression('#2011-02-03 04:05:06 \+01:00\s+exif\s+2011/trip/beach-copy.jpg#', $display);
        self::assertMatchesRegularExpression('#2011-02-03 04:05:06 \+01:00\s+duplicate\s+2011/trip/beach.jpg#', $display);
        self::assertMatchesRegularExpression('#2014-07-05 11:30:00 \+02:00\s+exif\s+2014.jpg#', $display);
        self::assertMatchesRegularExpression('/Would import\s+3/', $display);
        self::assertCount(0, $this->bobsFiles());
        self::assertDirectoryDoesNotExist($this->storage);
    }

    public function testReportHasALinePerFile(): void
    {
        $report = $this->source . '-report.csv';
        try {
            $this->import(['--report' => $report]);
            $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), file($report, FILE_IGNORE_NEW_LINES));
        } finally {
            @unlink($report);
        }

        self::assertSame(['path', 'sha1', 'date', 'source', 'status', 'file_id', 'custom_url', 'error'], array_shift($rows));
        $byPath = array_column($rows, null, 0);
        self::assertSame(['2011/notes.txt', '2011/trip/beach.jpg', '2014.jpg'], array_keys($byPath));
        self::assertSame(['2015-03-10T12:00:00+00:00', 'mtime', 'imported'], array_slice($byPath['2011/notes.txt'], 2, 3));
        $stored = $this->em->getRepository(StoredFile::class)->find((int) $byPath['2014.jpg'][5]);
        self::assertSame($stored->getCustomUrl(), $byPath['2014.jpg'][6]);
    }

    public function testAFailingFileIsReportedAndTheRunCarriesOn(): void
    {
        $locked = $this->source . '/2011/locked.txt';
        file_put_contents($locked, 'no access');
        chmod($locked, 0);
        try {
            self::assertSame(Command::SUCCESS, $this->import());
        } finally {
            chmod($locked, 0644);
        }

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Failed 2011/locked.txt', $display);
        self::assertMatchesRegularExpression('/Failed\s+1/', $display);
        self::assertCount(3, $this->bobsFiles(), 'the other files are imported');
    }

    public function testUnknownUserIsRejected(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute(['dir' => $this->source, '--user' => 'nobody']));
    }

    public function testAUserWithAPendingPurgeCannotImport(): void
    {
        $this->bob->setPurgeTs(time() + 600);
        $this->em->flush();

        self::assertSame(Command::FAILURE, $this->import());
        self::assertCount(0, $this->bobsFiles());
    }
}
