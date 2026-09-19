<?php

namespace App\Tests\Service;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Exception\UploadBlockedException;
use App\Repository\StoredFileRepository;
use App\Service\FileService;
use App\Service\PurgeNotCancellable;
use App\Service\PurgeService;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A purge is `users.purge_ts`: scheduling sets it, cancelling clears it, and while it is set the
 * user is frozen (files 404 by URL, library empty, uploads refused). Other users are never touched.
 */
final class PurgeServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PurgeFixtures $fixtures;
    private PurgeService $purge;
    private User $bob;
    private User $alice;
    private StoredFile $bobFile;
    private StoredFile $aliceFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->fixtures = new PurgeFixtures($this->em, $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $this->purge = $container->get(PurgeService::class);

        $this->bob = $this->fixtures->user('bob', 'bob12345');
        $this->alice = $this->fixtures->user('alice', 'alice123');
        $this->bobFile = $this->fixtures->file($this->bob);
        $this->fixtures->file($this->bob);
        $this->fixtures->file($this->bob, marked: true); // bob trashed one file himself
        $this->aliceFile = $this->fixtures->file($this->alice);
    }

    private function repo(): StoredFileRepository
    {
        return $this->em->getRepository(StoredFile::class);
    }

    public function testStatusOfAQuietAccount(): void
    {
        $status = $this->purge->status($this->bob);
        self::assertSame(
            ['pending' => false, 'purge_at' => null, 'total' => 3, 'marked' => 0, 'active' => 3, 'can_cancel' => false],
            $status
        );
    }

    public function testPurgeSchedulesTheGracePeriodAndFreezesTheAccount(): void
    {
        $before = time();
        $status = $this->purge->purge($this->bob, 'test');

        $grace = (int) static::getContainer()->getParameter('app.purge_grace_minutes');
        self::assertTrue($status['pending']);
        self::assertTrue($status['can_cancel']);
        self::assertSame(3, $status['marked'], 'while pending every file counts as marked for older app builds');
        self::assertSame(0, $status['active']);
        $ts = $this->fixtures->reload($this->bob)->getPurgeTs();
        self::assertGreaterThanOrEqual($before + $grace * 60, $ts);
        self::assertLessThanOrEqual(time() + $grace * 60, $ts);
        self::assertSame($ts, (new \DateTimeImmutable($status['purge_at']))->getTimestamp());

        // Nothing about the files themselves changed.
        foreach ($this->fixtures->filesOf($this->bob) as $file) {
            self::assertSame($file->getId() === $this->bobFile->getId() ? true : $file->getVisibilityStatus(), $file->getVisibilityStatus());
        }
        self::assertFalse($this->fixtures->reload($this->alice)->isPurging(), 'alice is untouched');
    }

    public function testASecondPurgeRequestKeepsTheOriginalDeadline(): void
    {
        $this->purge->purge($this->bob, 'test');
        $first = $this->fixtures->reload($this->bob)->getPurgeTs();
        $bob = $this->fixtures->reload($this->bob);
        $bob->setPurgeTs($first - 300); // pretend time passed
        $this->em->flush();

        $this->purge->purge($bob, 'test');
        self::assertSame($first - 300, $this->fixtures->reload($bob)->getPurgeTs());
    }

    public function testFrozenUserFilesVanishByUrlWhilePending(): void
    {
        self::assertNotNull($this->repo()->findFileByCustomURL($this->bobFile->getCustomUrl()));
        self::assertNotNull($this->repo()->findFileByCustomURLAnyVisibility($this->bobFile->getCustomUrl()));

        $this->purge->purge($this->bob, 'test');

        self::assertNull($this->repo()->findFileByCustomURL($this->bobFile->getCustomUrl()), 'direct link: 404');
        self::assertNull($this->repo()->findFileByCustomURLAnyVisibility($this->bobFile->getCustomUrl()), 'view page: 404');
        self::assertNotNull($this->repo()->findFileByCustomURL($this->aliceFile->getCustomUrl()), "alice's files still serve");
        self::assertTrue($this->repo()->ownerIsPurging($this->bobFile));
        self::assertFalse($this->repo()->ownerIsPurging($this->aliceFile));

        $this->purge->cancel($this->fixtures->reload($this->bob), 'test');
        self::assertNotNull($this->repo()->findFileByCustomURL($this->bobFile->getCustomUrl()), 'back after cancel');
    }

    public function testUploadsAreRefusedWhilePending(): void
    {
        $this->purge->purge($this->bob, 'test');
        $tmp = tempnam(sys_get_temp_dir(), 'purge');
        file_put_contents($tmp, 'hello');
        $upload = new UploadedFile($tmp, 'hello.txt', 'text/plain', null, true);

        $this->expectException(UploadBlockedException::class);
        static::getContainer()->get(FileService::class)->storeFormUploadFile($upload, $this->fixtures->reload($this->bob));
    }

    public function testCancelClearsTheDeadline(): void
    {
        $this->purge->purge($this->bob, 'test');
        $status = $this->purge->cancel($this->fixtures->reload($this->bob), 'test');

        self::assertFalse($status['pending']);
        self::assertNull($status['purge_at']);
        self::assertSame(3, $status['active']);
        self::assertNull($this->fixtures->reload($this->bob)->getPurgeTs());
    }

    public function testCancelIsRefusedWithoutAPendingPurge(): void
    {
        $this->expectException(PurgeNotCancellable::class);
        $this->purge->cancel($this->bob, 'test');
    }

    public function testStatusForAllListsUsersWithFilesOrAPendingPurge(): void
    {
        $nobody = $this->fixtures->user('nobody', 'nobody123');
        $all = $this->purge->statusForAll();
        self::assertSame([$this->bob->getId(), $this->alice->getId()], array_keys($all));
        self::assertSame(3, $all[$this->bob->getId()]['total']);

        $this->purge->purge($nobody, 'test');
        $all = $this->purge->statusForAll();
        self::assertArrayHasKey($nobody->getId(), $all, 'a pending purge shows even with zero files');
        self::assertTrue($all[$nobody->getId()]['pending']);
    }
}
