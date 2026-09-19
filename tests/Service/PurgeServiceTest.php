<?php

namespace App\Tests\Service;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Service\PurgeNotCancellable;
use App\Service\PurgeService;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The purge queues every file a user owns for deletion and can be undone only while it is still
 * a purge (all files marked). These tests pin that contract, including that other users' files
 * are never touched.
 */
final class PurgeServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PurgeFixtures $fixtures;
    private PurgeService $purge;
    private User $bob;
    private User $alice;

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
        $this->fixtures->file($this->bob);
        $this->fixtures->file($this->bob);
        $this->fixtures->file($this->bob, marked: true); // bob already trashed one file himself
        $this->fixtures->file($this->alice);
        $this->fixtures->file($this->alice, marked: true);
    }

    public function testStatusCountsFilesAndOnlyOffersCancelWhenEverythingIsMarked(): void
    {
        $status = $this->purge->status($this->bob);
        self::assertSame(['total' => 3, 'marked' => 1, 'active' => 2, 'pending' => false, 'can_cancel' => false], $status);
    }

    public function testPurgeMarksEveryFileOfTheUserAndNothingElse(): void
    {
        $marked = $this->purge->purge($this->bob, 'test');

        self::assertSame(2, $marked, 'only the files that were not already marked count');
        foreach ($this->fixtures->filesOf($this->bob) as $file) {
            self::assertTrue($file->markedForDeletion(), $file->getOriginalName() . ' should be queued');
            self::assertFalse($file->getVisibilityStatus());
        }
        $aliceStates = array_map(fn (StoredFile $f) => $f->markedForDeletion(), $this->fixtures->filesOf($this->alice));
        sort($aliceStates);
        self::assertSame([false, true], $aliceStates, "alice's files are untouched");

        $status = $this->purge->status($this->bob);
        self::assertSame(3, $status['marked']);
        self::assertTrue($status['pending']);
        self::assertTrue($status['can_cancel']);
    }

    public function testPurgeIsIdempotent(): void
    {
        $this->purge->purge($this->bob, 'test');
        self::assertSame(0, $this->purge->purge($this->bob, 'test'));
        self::assertSame(3, $this->purge->status($this->bob)['marked']);
    }

    public function testCancelRestoresEveryFileWhilePending(): void
    {
        $this->purge->purge($this->bob, 'test');
        $restored = $this->purge->cancel($this->bob, 'test');

        self::assertSame(3, $restored, 'the file bob trashed before the purge comes back too');
        foreach ($this->fixtures->filesOf($this->bob) as $file) {
            self::assertFalse($file->markedForDeletion());
            self::assertTrue($file->getVisibilityStatus());
        }
        self::assertSame(0, $this->purge->status($this->bob)['marked']);
        self::assertFalse($this->purge->status($this->bob)['can_cancel']);
    }

    public function testCancelIsRefusedWhenNotEveryFileIsMarked(): void
    {
        $this->expectException(PurgeNotCancellable::class);
        $this->expectExceptionMessage('2 of 3 still active');
        $this->purge->cancel($this->bob, 'test');
    }

    public function testCancelIsRefusedAfterAnUploadEndsThePurge(): void
    {
        $this->purge->purge($this->bob, 'test');
        $this->fixtures->file($this->bob); // a new upload: no longer "everything is marked"

        try {
            $this->purge->cancel($this->bob, 'test');
            self::fail('cancel should be refused');
        } catch (PurgeNotCancellable $e) {
            self::assertSame(1, $e->status['active']);
            self::assertFalse($e->status['can_cancel']);
        }
        // Nothing changed: the three purged files are still queued.
        self::assertSame(3, $this->purge->status($this->bob)['marked']);
    }

    public function testCancelIsRefusedForAUserWithoutFiles(): void
    {
        $nobody = $this->fixtures->user('nobody', 'nobody123');
        $this->expectException(PurgeNotCancellable::class);
        $this->expectExceptionMessage('no files to restore');
        $this->purge->cancel($nobody, 'test');
    }

    public function testStatusForAllListsOnlyUsersWithFiles(): void
    {
        $this->fixtures->user('nobody', 'nobody123');
        $all = $this->purge->statusForAll();
        self::assertSame([$this->bob->getId(), $this->alice->getId()], array_keys($all));
        self::assertSame(3, $all[$this->bob->getId()]['total']);
        self::assertSame(1, $all[$this->alice->getId()]['marked']);
    }
}
