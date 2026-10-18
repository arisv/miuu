<?php

namespace App\Tests\Service;

use App\Entity\FileNameChange;
use App\Entity\User;
use App\Exception\FileEditException;
use App\Service\FileRenameService;
use App\Service\FileService;
use App\Service\UserService;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Renames keep the extension, are recorded, can be reverted, and their history follows the file and user lifecycle. */
final class FileRenameServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PurgeFixtures $fixtures;
    private FileRenameService $renames;
    private User $alice;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->fixtures = new PurgeFixtures($this->em, $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $this->renames = new FileRenameService($this->em);
        $this->alice = $this->fixtures->user('alice', 'alice123');
    }

    public function testRenameKeepsTheExtensionAndRecordsTheChange(): void
    {
        $file = $this->fixtures->file($this->alice, false, 'IMG_0001.jpg');

        $change = $this->renames->rename($file, 'Holiday', $this->alice);

        self::assertSame('Holiday.jpg', $file->getOriginalName());
        self::assertSame('IMG_0001.jpg', $change->getOldName());
        self::assertSame('Holiday.jpg', $change->getNewName());
        self::assertSame($this->alice, $change->getRenamedBy());
        self::assertFalse($change->isRevert());
    }

    public function testNamesWithoutExtensionStayWithout(): void
    {
        $file = $this->fixtures->file($this->alice, false, 'README');
        $this->renames->rename($file, 'NOTES', $this->alice);
        self::assertSame('NOTES', $file->getOriginalName());
    }

    public function testUnchangedNameRecordsNothing(): void
    {
        $file = $this->fixtures->file($this->alice, false, 'photo.png');

        self::assertNull($this->renames->rename($file, 'photo', $this->alice));
        self::assertNotNull($this->renames->rename($file, 'Photo', $this->alice), 'case changes count');
        self::assertCount(1, $this->renames->history($file));
    }

    public function testInvalidNameChangesNothing(): void
    {
        $file = $this->fixtures->file($this->alice, false, 'photo.png');
        try {
            $this->renames->rename($file, 'a/b', $this->alice);
            self::fail('expected FileEditException');
        } catch (FileEditException $e) {
            self::assertSame(FileEditException::INVALID_NAME, $e->reason);
            self::assertSame('base_name', $e->field);
        }
        self::assertSame('photo.png', $file->getOriginalName());
        self::assertSame([], $this->renames->history($file));
    }

    public function testRevertRestoresTheOldNameAndIsRecorded(): void
    {
        $file = $this->fixtures->file($this->alice, false, 'a.png');
        $first = $this->renames->rename($file, 'b', $this->alice);
        $this->renames->rename($file, 'c', $this->alice);

        $revert = $this->renames->revert($file, $first->getId(), $this->alice);

        self::assertSame('a.png', $file->getOriginalName());
        self::assertTrue($revert->isRevert());
        self::assertSame('c.png', $revert->getOldName());
        $names = array_map(fn (FileNameChange $c) => $c->getNewName(), $this->renames->history($file));
        self::assertSame(['a.png', 'c.png', 'b.png'], $names, 'newest first');
    }

    public function testRevertToTheCurrentNameIsANoOp(): void
    {
        $file = $this->fixtures->file($this->alice, false, 'a.png');
        $first = $this->renames->rename($file, 'b', $this->alice);
        $this->renames->revert($file, $first->getId(), $this->alice);

        self::assertNull($this->renames->revert($file, $first->getId(), $this->alice));
        self::assertCount(2, $this->renames->history($file));
    }

    public function testEntriesOfAnotherFileCannotBeReverted(): void
    {
        $mine = $this->fixtures->file($this->alice, false, 'mine.png');
        $other = $this->fixtures->file($this->alice, false, 'other.png');
        $entry = $this->renames->rename($other, 'renamed', $this->alice);

        $this->expectExceptionObject(FileEditException::historyNotFound());
        $this->renames->revert($mine, $entry->getId(), $this->alice);
    }

    public function testHistoryGoesWithTheFile(): void
    {
        $file = $this->fixtures->file($this->alice, false, 'a.png');
        $this->renames->rename($file, 'b', $this->alice);

        static::getContainer()->get(FileService::class)->deleteFileFromStorage($file);

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(FileNameChange::class)->count([]));
    }

    public function testDeletingTheRenamerKeepsTheHistory(): void
    {
        $admin = $this->fixtures->user('admin', 'admin123', User::ROLE_ADMIN);
        $file = $this->fixtures->file($admin, false, 'a.png');
        $this->renames->rename($file, 'b', $this->alice);

        static::getContainer()->get(UserService::class)->deleteUser($admin, $this->alice->getId(), false);

        $this->em->clear();
        $entries = $this->em->getRepository(FileNameChange::class)->findAll();
        self::assertCount(1, $entries);
        self::assertNull($entries[0]->getRenamedBy());
    }
}
